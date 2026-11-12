import 'dart:convert';
import 'dart:math';

import 'package:drift/drift.dart' hide isNull;
import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:yekkola/core/db/app_database.dart';
import 'package:yekkola/core/providers.dart';
import 'package:yekkola/core/sync/outbox.dart';
import 'package:yekkola/core/sync/sync_engine.dart';
import 'package:yekkola/features/auth/domain/app_user.dart';
import 'package:yekkola/features/auth/domain/auth_state.dart';
import 'package:yekkola/features/learning/data/progress_sync.dart';

import '../support/fake_api.dart';

const _a = '01JLESSONA0000000000000000';
const _b = '01JLESSONB0000000000000000';

void main() {
  late AppDatabase db;
  late DateTime now;
  late OutboxStore outbox;
  late ProgressRepository progress;

  setUp(() {
    db = AppDatabase(DatabaseConnection(NativeDatabase.memory(), closeStreamsSynchronously: true));
    now = DateTime.utc(2026, 11, 12, 10);
    outbox = OutboxStore(db, clock: () => now);
    progress = ProgressRepository(db, outbox, clock: () => now);
  });

  tearDown(() => db.close());

  (SyncEngine, FakeApi) engine(FakeResponse Function(Map<String, Object?>? body) answer, {String? userId = 'user-a'}) {
    final api = FakeApi((_, body) => answer(body));
    final container = makeContainer(
      api,
      initial: SignedIn(user: AppUser.fromJson(userJson()), token: 't'),
    );
    return (
      SyncEngine(
        outbox: outbox,
        handlers: [ProgressOutboxHandler(container.read(dioProvider))],
        currentUserId: () => userId,
        random: Random(1),
      ),
      api,
    );
  }

  FakeResponse accept(Map<String, Object?>? body, {List<String> skip = const []}) {
    final uids = [for (final item in body!['items']! as List) (item as Map)['lesson_uid'] as String];
    return FakeResponse(200, {
      'data': {'accepted': uids.where((u) => !skip.contains(u)).toList(), 'skipped': skip, 'courses': <String, int>{}},
    });
  }

  Future<List<OutboxRow>> rows() => db.select(db.outbox).get();

  group('ProgressRepository', () {
    test('keeps the furthest position and completion, locally and in one pending row per lesson', () async {
      await progress.record(userId: 'user-a', lessonUid: _a, positionSeconds: 120);
      now = now.add(const Duration(seconds: 15));
      await progress.record(userId: 'user-a', lessonUid: _a, positionSeconds: 60); // seeked back
      await progress.record(userId: 'user-a', lessonUid: _a, completed: true);

      final pending = await rows();
      expect(pending, hasLength(1));
      expect(jsonDecode(pending.single.payload), {
        'lesson_uid': _a,
        'position_seconds': 120,
        'completed': true,
        'client_updated_at': '2026-11-12T10:00:15.000Z',
      });

      final local = await progress.positionOf('user-a', _a);
      expect(local!.positionSeconds, 120);
      expect(local.completed, isTrue);
      expect(await progress.positionOf('user-b', _a), isNull);
    });
  });

  group('SyncEngine', () {
    test('sends a batch, removes accepted rows and drops skipped ones (PRD-06 FR-05)', () async {
      await progress.record(userId: 'user-a', lessonUid: _a, positionSeconds: 30);
      await progress.record(userId: 'user-a', lessonUid: _b, positionSeconds: 40);
      final (subject, api) = engine((body) => accept(body, skip: [_b]));

      expect(await subject.flush(), SyncOutcome.synced);

      expect(api.requests.single.method, 'PUT');
      expect(api.requests.single.path, '/me/progress');
      expect(api.requests.single.headers['Authorization'], 'Bearer t');
      expect((api.bodies.single!['items']! as List).map((i) => (i as Map)['lesson_uid']), [_a, _b]);
      expect(await rows(), isEmpty);
    });

    test('offline: keeps everything and backs off', () async {
      await progress.record(userId: 'user-a', lessonUid: _a, positionSeconds: 30);
      final (subject, _) = engine((_) => throw StateError('no network'));

      expect(await subject.flush(), SyncOutcome.offline);

      final row = (await rows()).single;
      expect(row.attempts, 1);
      expect(row.nextAttemptAt.isAfter(now), isTrue);
      // Not due yet: the next run sends nothing.
      final (again, api) = engine((body) => accept(body));
      expect(await again.flush(), SyncOutcome.idle);
      expect(api.requests, isEmpty);

      now = now.add(const Duration(minutes: 1));
      expect(await again.flush(), SyncOutcome.synced);
      expect(await rows(), isEmpty);
    });

    test('server errors retry; a batch refused as invalid is dropped; a closed session keeps it', () async {
      await progress.record(userId: 'user-a', lessonUid: _a, positionSeconds: 30);

      final (failing, _) = engine((_) => const FakeResponse(503, {'status': 503, 'code': 'server.error'}));
      expect(await failing.flush(), SyncOutcome.failed);
      expect((await rows()).single.attempts, 1);

      now = now.add(const Duration(hours: 1));
      final (locked, _) = engine((_) => const FakeResponse(401, {'status': 401, 'code': 'auth.unauthenticated'}));
      expect(await locked.flush(), SyncOutcome.failed);
      expect(await rows(), hasLength(1));

      now = now.add(const Duration(hours: 1));
      final (invalid, _) = engine((_) => const FakeResponse(422, {'status': 422, 'code': 'validation.failed'}));
      await invalid.flush();
      expect(await rows(), isEmpty);
    });

    test("sends only the signed-in account's rows, and nothing when signed out", () async {
      await progress.record(userId: 'user-a', lessonUid: _a, positionSeconds: 30);
      await progress.record(userId: 'user-b', lessonUid: _b, positionSeconds: 40);

      final (signedOut, none) = engine((body) => accept(body), userId: null);
      expect(await signedOut.flush(), SyncOutcome.idle);
      expect(none.requests, isEmpty);

      final (subject, api) = engine((body) => accept(body));
      await subject.flush();
      expect((api.bodies.single!['items']! as List).single, containsPair('lesson_uid', _a));
      expect((await rows()).single.userId, 'user-b');
    });

    test('a save made while the batch was in flight is not lost', () async {
      await progress.record(userId: 'user-a', lessonUid: _a, positionSeconds: 30);
      late SyncEngine subject;
      (subject, _) = engine((body) {
        // The player saves again before the API answers.
        now = now.add(const Duration(seconds: 15));
        progress.record(userId: 'user-a', lessonUid: _a, positionSeconds: 45);
        return accept(body);
      });

      await subject.flush();
      await Future<void>.delayed(const Duration(milliseconds: 50));

      final pending = await rows();
      expect(pending, hasLength(1));
      expect(jsonDecode(pending.single.payload)['position_seconds'], 45);
    });

    test('sends timestamps the API accepts, even from a microsecond clock', () async {
      now = DateTime.utc(2026, 11, 12, 10, 0, 15, 123, 456);
      await progress.record(userId: 'user-a', lessonUid: _a, positionSeconds: 30);
      final (subject, api) = engine((body) => accept(body));

      await subject.flush();

      final sent = (api.bodies.single!['items']! as List).single as Map;
      // Laravel rule: Y-m-d\TH:i:s(.v)?Z — three fraction digits at most.
      expect(sent['client_updated_at'], '2026-11-12T10:00:15.123Z');
      expect(
        RegExp(r'^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(\.\d{3})?Z$').hasMatch(sent['client_updated_at'] as String),
        isTrue,
      );
    });

    test('a 422 naming one item drops only that row; the rest go in the next run', () async {
      await progress.record(userId: 'user-a', lessonUid: _a, positionSeconds: 30);
      await progress.record(userId: 'user-a', lessonUid: _b, positionSeconds: 40);
      var call = 0;
      final (subject, api) = engine((body) {
        if (call++ == 0) {
          return const FakeResponse(422, {
            'status': 422,
            'code': 'validation.failed',
            'errors': {
              'items.1.lesson_uid': ['The lesson uid is invalid.'],
            },
          });
        }
        return accept(body);
      });

      await subject.flush();
      await Future<void>.delayed(const Duration(milliseconds: 50));

      expect(api.requests, hasLength(2));
      expect((api.bodies.last!['items']! as List).single, containsPair('lesson_uid', _a));
      expect(await rows(), isEmpty);
    });

    test('an unexpected failure backs off instead of throwing', () async {
      await progress.record(userId: 'user-a', lessonUid: _a, positionSeconds: 30);
      // A captive portal answering 200 with something that isn't our API.
      final (subject, _) = engine((_) => const FakeResponse(200, ['not', 'our', 'api']));

      expect(await subject.flush(), SyncOutcome.failed);
      final row = (await rows()).single;
      expect(row.attempts, 1);
      expect(row.nextAttemptAt.isAfter(now), isTrue);
    });

    test('a save while the API is failing keeps the backoff', () async {
      await progress.record(userId: 'user-a', lessonUid: _a, positionSeconds: 30);
      final (failing, _) = engine((_) => const FakeResponse(503, {'status': 503, 'code': 'server.error'}));
      await failing.flush();
      final waitUntil = (await rows()).single.nextAttemptAt;

      await progress.record(userId: 'user-a', lessonUid: _a, positionSeconds: 45);

      final row = (await rows()).single;
      expect(row.nextAttemptAt, waitUntil);
      expect(jsonDecode(row.payload)['position_seconds'], 45);
    });

    test('stops when the account changes during a run', () async {
      for (var n = 0; n < 150; n++) {
        await progress.record(
          userId: 'user-a',
          lessonUid: '01JLESSON${n.toString().padLeft(17, '0')}',
          positionSeconds: n,
        );
      }
      String? current = 'user-a';
      final api = FakeApi((_, body) {
        current = 'user-b'; // signed out and in as someone else while the first batch was in flight
        return accept(body);
      });
      final container = makeContainer(
        api,
        initial: SignedIn(user: AppUser.fromJson(userJson()), token: 't'),
      );
      final subject = SyncEngine(
        outbox: outbox,
        handlers: [ProgressOutboxHandler(container.read(dioProvider))],
        currentUserId: () => current,
      );

      await subject.flush();

      expect(api.requests, hasLength(1), reason: 'the second batch is not sent with the new token');
      expect(await rows(), hasLength(50));
    });

    test('backs off exponentially up to 15 minutes', () {
      final (subject, _) = engine((body) => accept(body));
      expect(subject.backoff(1).inSeconds, inInclusiveRange(4, 6));
      expect(subject.backoff(3).inSeconds, inInclusiveRange(16, 24));
      expect(subject.backoff(30).inMinutes, inInclusiveRange(12, 18));
    });
  });
}
