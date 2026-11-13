import 'dart:convert';
import 'dart:io';
import 'dart:math';

import 'package:drift/drift.dart' hide isNull, isNotNull;
import 'package:dio/dio.dart' show RequestOptions;
import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:yekkola/core/api/api_exception.dart';
import 'package:yekkola/core/db/app_database.dart';
import 'package:yekkola/core/providers.dart';
import 'package:yekkola/core/sync/outbox.dart';
import 'package:yekkola/core/sync/sync_engine.dart';
import 'package:yekkola/features/auth/domain/app_user.dart';
import 'package:yekkola/features/auth/domain/auth_state.dart';
import 'package:yekkola/features/learning/data/document_repository.dart';
import 'package:yekkola/features/learning/data/quiz_repository.dart';

import '../../support/fake_api.dart';

const _user = '01JUSER00000000000000000000';
const _lesson = '01JLESSONQ0000000000000000';

Map<String, Object?> attemptJson(String id, {int? secondsLeft, bool graded = false}) => {
  'id': id,
  'lesson_uid': _lesson,
  'started_at': '2026-11-13T10:00:00+00:00',
  'ends_at': secondsLeft == null ? null : '2026-11-13T10:10:00+00:00',
  'seconds_left': graded ? null : secondsLeft,
  'submitted_at': graded ? '2026-11-13T10:05:00+00:00' : null,
  'pass_mark_pct': 60,
  'score_pct': graded ? 100 : null,
  'passed': graded ? true : null,
  'timed_out': false,
  'questions': [
    {
      'id': 'q1',
      'type': 'single',
      'prompt': '2 + 2 ?',
      'points': 1,
      'options': [
        {'id': 'o1', 'text': '4'},
        {'id': 'o2', 'text': '5'},
      ],
      'chosen': graded ? ['o1'] : null,
      'correct': graded ? ['o1'] : null,
      'answered_correctly': graded ? true : null,
      'explanation': graded ? 'Basic.' : null,
    },
  ],
};

void main() {
  late AppDatabase db;
  late OutboxStore outbox;
  late DateTime now;

  setUp(() {
    db = AppDatabase(DatabaseConnection(NativeDatabase.memory(), closeStreamsSynchronously: true));
    now = DateTime.utc(2026, 11, 13, 10);
    outbox = OutboxStore(db, clock: () => now);
  });

  tearDown(() => db.close());

  (QuizRepository, SyncEngine, FakeApi) setup(FakeResponse Function(RequestOptions r, Map<String, Object?>? b) answer) {
    final api = FakeApi(answer);
    final container = makeContainer(
      api,
      initial: SignedIn(user: AppUser.fromJson(userJson()), token: 't'),
    );
    addTearDown(container.dispose);
    final dio = container.read(dioProvider);
    return (
      QuizRepository(dio, db, outbox, clock: () => now),
      SyncEngine(outbox: outbox, handlers: [QuizSubmitHandler(dio, db)], currentUserId: () => _user, random: Random(1)),
      api,
    );
  }

  test('answers survive resuming the same attempt; a new attempt starts blank', () async {
    var id = 'att1';
    final (repo, _, _) = setup((_, _) => FakeResponse(200, {'data': attemptJson(id, secondsLeft: 600)}));

    await repo.start(_user, _lesson);
    await repo.answer(_user, _lesson, 'q1', ['o1']);
    now = now.add(const Duration(minutes: 2));
    final resumed = await repo.start(_user, _lesson);
    expect(resumed.answers, {
      'q1': ['o1'],
    });
    expect(resumed.secondsLeft(now.add(const Duration(seconds: 30))), 570);

    id = 'att2';
    expect((await repo.start(_user, _lesson)).answers, isEmpty);
  });

  test('hand-in queues once, is graded on sync and the result is kept', () async {
    final (repo, engine, api) = setup((r, _) {
      if (r.method == 'POST') return FakeResponse(201, {'data': attemptJson('att1')});
      return FakeResponse(200, {'data': attemptJson('att1', graded: true)});
    });

    await repo.start(_user, _lesson);
    // A save still in flight when the time runs out: the hand-in carries the screen's answers and the late
    // save can't reopen it.
    final late = repo.answer(_user, _lesson, 'q1', ['o2']);
    await repo.handIn(
      _user,
      _lesson,
      answers: {
        'q1': ['o1'],
      },
    );
    await late;
    expect((await repo.local(_user, _lesson))!.handedIn, isTrue);
    await repo.answer(_user, _lesson, 'q1', ['o2']); // ignored once handed in
    await repo.handIn(_user, _lesson); // no second row
    expect(await db.select(db.outbox).get(), hasLength(1));

    await engine.flush();

    final put = api.requests.last;
    expect(put.method, 'PUT');
    expect(put.path, endsWith('/quiz-attempts/att1'));
    expect(api.bodies.last, {
      'answers': {
        'q1': ['o1'],
      },
    });
    expect(await db.select(db.outbox).get(), isEmpty);
    expect((await repo.result(_user, _lesson))!.scorePct, 100);
    expect(await repo.local(_user, _lesson), isNull);
  });

  test('a final refusal is recorded and dropped; server errors and account 403s stay queued', () async {
    var answer = const FakeResponse(500, {'status': 500, 'code': 'server_error'});
    final (repo, engine, _) = setup(
      (r, _) => r.method == 'POST' ? FakeResponse(201, {'data': attemptJson('att1')}) : answer,
    );
    Future<void> retryNow() => db.update(db.outbox).write(OutboxCompanion(nextAttemptAt: Value(now)));

    await repo.start(_user, _lesson);
    await repo.handIn(_user, _lesson);

    await engine.flush();
    expect(await db.select(db.outbox).get(), hasLength(1));

    answer = const FakeResponse(403, {'status': 403, 'code': 'account.suspended'});
    await retryNow();
    await engine.flush();
    expect(await db.select(db.outbox).get(), hasLength(1));
    expect(await repo.refusal(_user, _lesson), isNull);

    answer = const FakeResponse(403, {'status': 403, 'code': 'enrollment.required'});
    await retryNow();
    await engine.flush();
    expect(await db.select(db.outbox).get(), isEmpty);
    expect(await repo.refusal(_user, _lesson), 'enrollment.required');
    expect(await repo.result(_user, _lesson), isNull);
  });

  test('a failed new start keeps the previous result', () async {
    var online = true;
    final (repo, engine, _) = setup((r, _) {
      if (!online) return const FakeResponse(409, {'status': 409, 'code': 'quiz.max_attempts_reached'});
      return r.method == 'POST'
          ? FakeResponse(201, {'data': attemptJson('att1')})
          : FakeResponse(200, {'data': attemptJson('att1', graded: true)});
    });
    await repo.start(_user, _lesson);
    await repo.handIn(_user, _lesson);
    await engine.flush();

    online = false;
    await expectLater(repo.start(_user, _lesson), throwsA(isA<ApiException>()));
    expect(await repo.result(_user, _lesson), isNotNull);
  });

  group('documents', () {
    late Directory root;

    setUp(() async => root = await Directory.systemTemp.createTemp('docs'));
    tearDown(() async {
      if (await root.exists()) await root.delete(recursive: true);
    });

    DocumentRepository repo(FakeApi api, FakeApi files) {
      final a = makeContainer(api);
      final f = makeContainer(files);
      addTearDown(a.dispose);
      addTearDown(f.dispose);
      return DocumentRepository(
        api: a.read(dioProvider),
        files: f.read(dioProvider),
        root: root,
        pollEvery: Duration.zero,
      );
    }

    test('waits out the stamping (202) then saves the copy per account', () async {
      var asks = 0;
      final api = FakeApi((_, _) {
        asks++;
        return asks < 3
            ? const FakeResponse(202, {
                'data': {'status': 'processing', 'retry_after': 3},
              })
            : const FakeResponse(200, {
                'data': {'url': 'https://files.test/doc.pdf'},
              });
      });
      final files = FakeApi((_, _) => const FakeResponse(200, {'pdf': true}));
      final docs = repo(api, files);

      final file = await docs.fetch(_user, _lesson);
      expect(asks, 3);
      expect(file.path, contains(_user));
      expect(jsonDecode(await file.readAsString()), {'pdf': true});
      expect((await docs.saved(_user, _lesson))?.path, file.path);
      expect(await File('${file.path}.part').exists(), isFalse);

      await docs.deleteAll();
      expect(await docs.saved(_user, _lesson), isNull);
    });

    test('gives up after the wait with still_processing', () async {
      final api = FakeApi(
        (_, _) => const FakeResponse(202, {
          'data': {'status': 'processing', 'retry_after': 3},
        }),
      );
      final docs = repo(api, FakeApi((_, _) => const FakeResponse(500)));

      await expectLater(
        docs.fetch(_user, _lesson, maxWait: Duration.zero),
        throwsA(isA<ApiException>().having((e) => e.code, 'code', 'document.still_processing')),
      );
      expect(() => docs.fileFor('../x', _lesson), throwsArgumentError);
    });
  });
}
