import 'package:drift/drift.dart';
import 'package:uuid/uuid.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/db/app_database.dart';

/// Idempotency keys for money writes (project-context: "Order creation requires an Idempotency-Key"), kept in
/// the local database so they survive the app being killed. The same action keeps its key across retries and
/// restarts — a double tap, a lost answer or a crash never creates two orders or two payment requests — and a
/// new action gets a new key. Mirrors apps/web's idempotency.ts, which keeps keys only for a browser session:
/// here a key lives at most [maxAge] (the API keeps results that long), so an abandoned attempt doesn't come
/// back days later.
class IdempotencyKeys {
  IdempotencyKeys(this._db, {this.maxAge = const Duration(hours: 24), DateTime Function()? clock})
    : _clock = clock ?? DateTime.now;

  final AppDatabase _db;
  final Duration maxAge;
  final DateTime Function() _clock;
  static const _uuid = Uuid();
  static const _prefix = 'idem:';

  static String _name(String scope) => '$_prefix$scope';

  Future<String> keyFor(String scope) async {
    final now = _clock().toUtc();
    final existing = await (_db.select(_db.syncState)..where((s) => s.key.equals(_name(scope)))).getSingleOrNull();
    if (existing != null) {
      final (key, createdAt) = _parse(existing.value);
      if (createdAt != null && now.difference(createdAt) < maxAge) return key;
    }
    await _pruneStale(now);
    final created = _uuid.v4();
    await _db
        .into(_db.syncState)
        .insertOnConflictUpdate(
          SyncStateCompanion.insert(key: _name(scope), value: '$created|${now.toIso8601String()}'),
        );
    return created;
  }

  Future<void> forget(String scope) => (_db.delete(_db.syncState)..where((s) => s.key.equals(_name(scope)))).go();

  /// The API stored its answer against the key (2xx and 400/404/410/422): replaying it would only return the
  /// same answer, so the next try is a new action. Network errors, 5xx and 409 are not final: retrying with
  /// the same key is what makes them safe.
  static bool isFinalAnswer(Object error) => error is ApiException && const {400, 404, 410, 422}.contains(error.status);

  /// Runs one money write under [scope]'s key and forgets the key once the API has given a final answer.
  /// [onResult] runs before the key is forgotten (e.g. remember the new order), so a crash in between never
  /// leaves neither.
  Future<T> run<T>(
    String scope,
    Future<T> Function(String key) write, {
    Future<void> Function(T result)? onResult,
  }) async {
    final key = await keyFor(scope);
    try {
      final result = await write(key);
      await onResult?.call(result);
      await forget(scope);
      return result;
    } catch (e) {
      if (isFinalAnswer(e)) await forget(scope);
      rethrow;
    }
  }

  static (String, DateTime?) _parse(String value) {
    final parts = value.split('|');
    return (parts.first, parts.length > 1 ? DateTime.tryParse(parts[1]) : null);
  }

  Future<void> _pruneStale(DateTime now) async {
    final rows = await (_db.select(_db.syncState)..where((s) => s.key.like('$_prefix%'))).get();
    for (final row in rows) {
      final (_, createdAt) = _parse(row.value);
      if (createdAt == null || now.difference(createdAt) >= maxAge) {
        await (_db.delete(_db.syncState)..where((s) => s.key.equals(row.key))).go();
      }
    }
  }
}
