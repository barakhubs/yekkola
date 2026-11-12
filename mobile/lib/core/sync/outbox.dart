import 'dart:convert';

import 'package:drift/drift.dart';

import '../db/app_database.dart';

/// Merges a new write into the pending one for the same key (e.g. two progress saves for one lesson).
typedef OutboxMerge = Map<String, Object?> Function(Map<String, Object?> pending, Map<String, Object?> next);

/// Writes made offline-first: stored here, sent by the [SyncEngine] when the API is reachable.
class OutboxStore {
  OutboxStore(this._db, {DateTime Function()? clock}) : _clock = clock ?? DateTime.now;

  final AppDatabase _db;
  final DateTime Function() _clock;

  /// Queues [payload] (merged into a pending row for the same key). A merged row is due at once again.
  Future<void> put({
    required String userId,
    required String kind,
    required String key,
    required Map<String, Object?> payload,
    required OutboxMerge merge,
  }) => _db.transaction(() async {
    final now = _clock().toUtc();
    final existing = await (_db.select(
      _db.outbox,
    )..where((o) => o.userId.equals(userId) & o.kind.equals(kind) & o.dedupeKey.equals(key))).getSingleOrNull();

    if (existing == null) {
      await _db
          .into(_db.outbox)
          .insert(
            OutboxCompanion.insert(
              userId: userId,
              kind: kind,
              dedupeKey: key,
              payload: jsonEncode(payload),
              createdAt: now,
              updatedAt: now,
              nextAttemptAt: now,
            ),
          );
      return;
    }

    final merged = merge((jsonDecode(existing.payload) as Map).cast<String, Object?>(), payload);
    await (_db.update(_db.outbox)..where((o) => o.id.equals(existing.id))).write(
      OutboxCompanion(
        payload: Value(jsonEncode(merged)),
        updatedAt: Value(now),
        revision: Value(existing.revision + 1),
        // A row backing off (API down) keeps waiting: saving every 15 s must not hammer a failing API.
        nextAttemptAt: Value(existing.nextAttemptAt.isAfter(now) ? existing.nextAttemptAt : now),
      ),
    );
  });

  /// Rows of [userId] due now, oldest first.
  Future<List<OutboxRow>> due(String userId, {int limit = 500}) =>
      (_db.select(_db.outbox)
            ..where((o) => o.userId.equals(userId) & o.nextAttemptAt.isSmallerOrEqualValue(_clock().toUtc()))
            ..orderBy([(o) => OrderingTerm.asc(o.createdAt), (o) => OrderingTerm.asc(o.id)])
            ..limit(limit))
          .get();

  Future<int> pendingCount(String userId) async {
    final count = _db.outbox.id.count();
    final row =
        await (_db.selectOnly(_db.outbox)
              ..addColumns([count])
              ..where(_db.outbox.userId.equals(userId)))
            .getSingle();
    return row.read(count) ?? 0;
  }

  /// Sent (or permanently refused). Only rows not changed since they were read: a write merged in while
  /// the request was in flight stays queued.
  Future<void> remove(Iterable<OutboxRow> rows) => _db.transaction(() async {
    for (final row in rows) {
      await (_db.delete(_db.outbox)..where((o) => o.id.equals(row.id) & o.revision.equals(row.revision))).go();
    }
  });

  /// Failed for a reason worth retrying: try again after [delay]. A row merged since it was read is due as is.
  Future<void> retryLater(Iterable<OutboxRow> rows, Duration Function(int attempts) delay, String error) =>
      _db.transaction(() async {
        final now = _clock().toUtc();
        for (final row in rows) {
          final attempts = row.attempts + 1;
          await (_db.update(_db.outbox)..where((o) => o.id.equals(row.id) & o.revision.equals(row.revision))).write(
            OutboxCompanion(
              attempts: Value(attempts),
              nextAttemptAt: Value(now.add(delay(attempts))),
              lastError: Value(error),
            ),
          );
        }
      });
}
