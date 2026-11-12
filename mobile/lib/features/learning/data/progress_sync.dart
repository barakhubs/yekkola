import 'dart:convert';
import 'dart:math';

import 'package:dio/dio.dart';
import 'package:drift/drift.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/api/generated/yekkola_api.dart';
import '../../../core/db/app_database.dart';
import '../../../core/sync/outbox.dart';
import '../../../core/sync/sync_engine.dart';

/// Lesson progress, offline-first (PRD-06 FR-05): saved locally at once (the player resumes from it, online
/// or not) and queued for `PUT /me/progress`. Both sides merge monotonically — furthest position wins,
/// completion never regresses — so the order in which phones sync doesn't matter. The API decides
/// completion for video/audio (90 % watched); the app only sends positions for those.
class ProgressRepository {
  ProgressRepository(this._db, this._outbox, {DateTime Function()? clock}) : _clock = clock ?? DateTime.now;

  static const kind = 'progress';

  final AppDatabase _db;
  final OutboxStore _outbox;
  final DateTime Function() _clock;

  Future<void> record({
    required String userId,
    required String lessonUid,
    int? positionSeconds,
    bool? completed,
  }) async {
    final now = _clock().toUtc();
    final int? position = positionSeconds?.clamp(0, 86400);

    await _db.transaction(() async {
      final current = await positionOf(userId, lessonUid);
      await _db
          .into(_db.lessonProgress)
          .insertOnConflictUpdate(
            LessonProgressCompanion.insert(
              userId: userId,
              lessonUid: lessonUid,
              positionSeconds: Value(max(current?.positionSeconds ?? 0, position ?? 0)),
              completed: Value((current?.completed ?? false) || (completed ?? false)),
              updatedAt: now,
            ),
          );
      await _outbox.put(
        userId: userId,
        kind: kind,
        key: lessonUid,
        payload: {
          'lesson_uid': lessonUid,
          'position_seconds': ?position,
          if (completed == true) 'completed': true,
          'client_updated_at': now.toIso8601String(),
        },
        merge: mergeProgress,
      );
    });
  }

  /// Where to resume [lessonUid] on this phone.
  Future<LessonProgressRow?> positionOf(String userId, String lessonUid) => (_db.select(
    _db.lessonProgress,
  )..where((p) => p.userId.equals(userId) & p.lessonUid.equals(lessonUid))).getSingleOrNull();

  /// Two pending saves of one lesson: the furthest position, completion if either completed, the latest time.
  static Map<String, Object?> mergeProgress(Map<String, Object?> pending, Map<String, Object?> next) {
    final positions = [pending['position_seconds'], next['position_seconds']].whereType<int>();
    return {
      'lesson_uid': next['lesson_uid'],
      if (positions.isNotEmpty) 'position_seconds': positions.reduce(max),
      if (pending['completed'] == true || next['completed'] == true) 'completed': true,
      'client_updated_at': next['client_updated_at'] ?? pending['client_updated_at'],
    };
  }
}

/// Sends queued progress in batches of 100 (the endpoint's limit). Lessons the API skips (not in an active
/// enrollment's live version any more) are dropped, not retried.
class ProgressOutboxHandler implements OutboxHandler {
  ProgressOutboxHandler(this._dio);

  final Dio _dio;

  @override
  String get kind => ProgressRepository.kind;

  @override
  int get batchSize => 100;

  @override
  Future<OutboxResult> send(List<OutboxRow> rows) async {
    final items = [
      for (final row in rows) SyncProgressRequestItemsItem.fromJson((jsonDecode(row.payload) as Map).cast()),
    ];
    // Read raw: the spec types `courses` loosely and the app doesn't need it (TODO.md Phase 3).
    final response = await ApiException.guard(
      () => _dio.put<Map<String, Object?>>('/me/progress', data: SyncProgressRequest(items: items).toJson()),
    );
    final data = (response.data?['data'] as Map?)?.cast<String, Object?>() ?? const {};
    final accepted = (data['accepted'] as List? ?? const []).map((e) => '$e').toSet();
    final skipped = (data['skipped'] as List? ?? const []).map((e) => '$e').toSet();

    return OutboxResult(
      sent: rows.where((r) => accepted.contains(r.dedupeKey)).toList(),
      dropped: rows.where((r) => skipped.contains(r.dedupeKey)).toList(),
    );
  }
}
