import 'dart:convert';

import 'package:dio/dio.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/api/generated/yekkola_api.dart';
import '../../../core/db/app_database.dart';
import '../../../core/sync/outbox.dart';
import '../../../core/sync/sync_engine.dart';

/// A quiz attempt as kept on this phone: the questions the API dealt (never the answer key), the student's
/// answers so far, and whether it was handed in here (results come after sync — PRD-06 FR-08).
class LocalAttempt {
  const LocalAttempt({required this.attempt, required this.fetchedAt, this.answers = const {}, this.handedIn = false});

  factory LocalAttempt.fromJson(Map<String, Object?> json) => LocalAttempt(
    attempt: QuizAttemptResource.fromJson((json['attempt']! as Map).cast()),
    fetchedAt: DateTime.parse(json['fetched_at']! as String),
    answers: {
      for (final e in ((json['answers'] as Map?) ?? const {}).entries)
        '${e.key}': [for (final id in e.value as List) '$id'],
    },
    handedIn: json['handed_in'] == true,
  );

  final QuizAttemptResource attempt;

  /// When the API sent it: the countdown runs from `seconds_left` at that moment (not the phone's clock).
  final DateTime fetchedAt;

  /// Question id → chosen option ids.
  final Map<String, List<String>> answers;
  final bool handedIn;

  /// Seconds left now, or null without a time limit.
  int? secondsLeft(DateTime now) {
    final left = attempt.secondsLeft;
    if (left == null) return null;
    return (left - now.difference(fetchedAt).inSeconds).clamp(0, left);
  }

  LocalAttempt copyWith({Map<String, List<String>>? answers, bool? handedIn}) => LocalAttempt(
    attempt: attempt,
    fetchedAt: fetchedAt,
    answers: answers ?? this.answers,
    handedIn: handedIn ?? this.handedIn,
  );

  Map<String, Object?> toJson() => {
    'attempt': attempt.toJson(),
    'fetched_at': fetchedAt.toUtc().toIso8601String(),
    'answers': answers,
    'handed_in': handedIn,
  };
}

/// Quizzes, offline-first where the API allows (PRD-06 FR-07, FR-08): starting an attempt needs the network
/// (the API deals the questions and starts any time limit); after that the attempt and the answers live on the
/// phone, so it can be finished offline. Handing in queues the answers in the outbox; the graded result
/// (score, corrections, explanations) is stored when the API answers. The answer key never reaches the phone
/// before grading.
class QuizRepository {
  QuizRepository(this._api, this._db, this._outbox, {DateTime Function()? clock}) : _clock = clock ?? DateTime.now;

  static const kind = 'quiz_submit';

  final Dio _api;
  final AppDatabase _db;
  final OutboxStore _outbox;
  final DateTime Function() _clock;

  /// Writes run one after another, so a late answer can't overwrite a hand-in (or miss it).
  Future<void> _last = Future.value();

  Future<T> _serial<T>(Future<T> Function() task) {
    final run = _last.then((_) => task());
    _last = run.then((_) {}, onError: (_) {});
    return run;
  }

  static String attemptKey(String userId, String lessonUid) => 'quiz_attempt:$userId:$lessonUid';
  static String resultKey(String userId, String lessonUid) => 'quiz_result:$userId:$lessonUid';
  static String failedKey(String userId, String lessonUid) => 'quiz_failed:$userId:$lessonUid';

  /// Starts an attempt, or resumes the open one (the API decides; 201 or 200). The previous result is
  /// cleared only once the API has answered, so a failed start (offline, no attempts left) keeps it.
  Future<LocalAttempt> start(String userId, String lessonUid) async {
    final response = await ApiException.guard(
      () => _api.post<Map<String, Object?>>(
        '/lessons/$lessonUid/quiz-attempts',
        options: Options(validateStatus: (s) => s == 200 || s == 201),
      ),
    );
    final attempt = QuizAttemptResource.fromJson((response.data!['data']! as Map).cast());
    final fetchedAt = _clock().toUtc();
    return _serial(() async {
      final previous = await local(userId, lessonUid);
      final local0 = LocalAttempt(
        attempt: attempt,
        fetchedAt: fetchedAt,
        // Resuming the same attempt keeps the answers already given on this phone.
        answers: previous != null && previous.attempt.id == attempt.id ? previous.answers : const {},
      );
      await _db.transaction(() async {
        await _write(attemptKey(userId, lessonUid), local0.toJson());
        await _delete(resultKey(userId, lessonUid));
        await _delete(failedKey(userId, lessonUid));
      });
      return local0;
    });
  }

  Future<LocalAttempt?> local(String userId, String lessonUid) async {
    final json = await _read(attemptKey(userId, lessonUid));
    return json == null ? null : LocalAttempt.fromJson(json);
  }

  Future<void> answer(String userId, String lessonUid, String questionId, List<String> optionIds) => _serial(() async {
    final current = await local(userId, lessonUid);
    if (current == null || current.handedIn) return;
    await _write(
      attemptKey(userId, lessonUid),
      current.copyWith(answers: {...current.answers, questionId: optionIds}).toJson(),
    );
  });

  /// Hands in: queued for the API (sent now if online, else at the next sync). [answers] are the ones on screen
  /// (the stored ones if omitted), so the last choice counts even if its save hasn't landed yet.
  Future<void> handIn(String userId, String lessonUid, {Map<String, List<String>>? answers}) => _serial(() async {
    final current = await local(userId, lessonUid);
    if (current == null || current.handedIn) return;
    final handed = current.copyWith(answers: answers ?? current.answers, handedIn: true);
    await _db.transaction(() async {
      await _write(attemptKey(userId, lessonUid), handed.toJson());
      await _outbox.put(
        userId: userId,
        kind: kind,
        key: current.attempt.id,
        payload: {'attempt_id': current.attempt.id, 'lesson_uid': lessonUid, 'answers': handed.answers},
        merge: (_, next) => next,
      );
    });
  });

  /// The graded attempt the API returned, once synced.
  Future<QuizAttemptResource?> result(String userId, String lessonUid) async {
    final json = await _read(resultKey(userId, lessonUid));
    return json == null ? null : QuizAttemptResource.fromJson(json);
  }

  /// Why a handed-in attempt was refused (access ended…), if it was.
  Future<String?> refusal(String userId, String lessonUid) async =>
      (await _read(failedKey(userId, lessonUid)))?['code'] as String?;

  Future<Map<String, Object?>?> _read(String key) async {
    final row = await (_db.select(_db.syncState)..where((s) => s.key.equals(key))).getSingleOrNull();
    return row == null ? null : (jsonDecode(row.value) as Map).cast<String, Object?>();
  }

  Future<void> _write(String key, Map<String, Object?> json) =>
      _db.into(_db.syncState).insertOnConflictUpdate(SyncStateCompanion.insert(key: key, value: jsonEncode(json)));

  Future<void> _delete(String key) => (_db.delete(_db.syncState)..where((s) => s.key.equals(key))).go();
}

/// Sends handed-in attempts, one per request (`PUT /quiz-attempts/{id}`; a retry returns the stored result).
/// The graded result is kept for the quiz screen; a final refusal (access ended, attempt gone, invalid answers)
/// is recorded so the student is told rather than left waiting. Anything else (network, 5xx, a suspended
/// account's 403…) stays queued for the account, like every outbox row.
class QuizSubmitHandler implements OutboxHandler {
  QuizSubmitHandler(this._api, this._db);

  final Dio _api;
  final AppDatabase _db;

  @override
  String get kind => QuizRepository.kind;

  @override
  int get batchSize => 1;

  /// Refusals that won't change by retrying.
  static bool isFinal(ApiException e) =>
      e.status == 404 ||
      e.status == 410 ||
      e.status == 422 ||
      const {'enrollment.required', 'enrollment.course_taken_down', 'lesson.not_found'}.contains(e.code);

  @override
  Future<OutboxResult> send(List<OutboxRow> rows) async {
    final row = rows.single;
    final payload = (jsonDecode(row.payload) as Map).cast<String, Object?>();
    final lessonUid = payload['lesson_uid']! as String;
    try {
      final response = await ApiException.guard(
        () => _api.put<Map<String, Object?>>(
          '/quiz-attempts/${payload['attempt_id']}',
          data: {'answers': payload['answers']},
        ),
      );
      final graded = (response.data!['data']! as Map).cast<String, Object?>();
      await _db.transaction(() async {
        await _db
            .into(_db.syncState)
            .insertOnConflictUpdate(
              SyncStateCompanion.insert(
                key: QuizRepository.resultKey(row.userId, lessonUid),
                value: jsonEncode(graded),
              ),
            );
        await (_db.delete(
          _db.syncState,
        )..where((s) => s.key.equals(QuizRepository.attemptKey(row.userId, lessonUid)))).go();
      });
      return OutboxResult(sent: [row]);
    } on ApiException catch (e) {
      if (!isFinal(e)) rethrow; // the engine retries (and keeps it for the account)
      await _db
          .into(_db.syncState)
          .insertOnConflictUpdate(
            SyncStateCompanion.insert(
              key: QuizRepository.failedKey(row.userId, lessonUid),
              value: jsonEncode({'code': e.code}),
            ),
          );
      return OutboxResult(dropped: [row]);
    }
  }
}
