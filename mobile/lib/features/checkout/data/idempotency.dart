import 'package:uuid/uuid.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/db/app_database.dart';

/// Idempotency keys for money writes (project-context: "Order creation requires an Idempotency-Key"), kept in
/// the local database so they survive the app being killed. The same action keeps its key across retries and
/// restarts — a double tap, a lost answer or a crash never creates two orders or two payment requests — and a
/// new action gets a new key. Mirrors apps/web's idempotency.ts.
class IdempotencyKeys {
  IdempotencyKeys(this._db);

  final AppDatabase _db;
  static const _uuid = Uuid();

  static String _name(String scope) => 'idem:$scope';

  Future<String> keyFor(String scope) async {
    final existing = await (_db.select(_db.syncState)..where((s) => s.key.equals(_name(scope)))).getSingleOrNull();
    if (existing != null) return existing.value;
    final created = _uuid.v4();
    await _db.into(_db.syncState).insertOnConflictUpdate(SyncStateCompanion.insert(key: _name(scope), value: created));
    return created;
  }

  Future<void> forget(String scope) => (_db.delete(_db.syncState)..where((s) => s.key.equals(_name(scope)))).go();

  /// The API stored its answer against the key (2xx and 400/404/410/422): replaying it would only return the
  /// same answer, so the next try is a new action. Network errors, 5xx and 409 are not final: retrying with
  /// the same key is what makes them safe.
  static bool isFinalAnswer(Object error) => error is ApiException && const {400, 404, 410, 422}.contains(error.status);

  /// Runs one money write under [scope]'s key and forgets the key once the API has given a final answer.
  Future<T> run<T>(String scope, Future<T> Function(String key) write) async {
    final key = await keyFor(scope);
    try {
      final result = await write(key);
      await forget(scope);
      return result;
    } catch (e) {
      if (isFinalAnswer(e)) await forget(scope);
      rethrow;
    }
  }
}
