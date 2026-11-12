import 'package:drift/drift.dart' hide isNull;
import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:yekkola/core/api/api_exception.dart';
import 'package:yekkola/core/cache/response_cache.dart';
import 'package:yekkola/core/db/app_database.dart';
import 'package:yekkola/core/i18n/formatters.dart';
import 'package:yekkola/features/catalog/data/catalog_repository.dart';

void main() {
  late AppDatabase db;
  late DateTime now;
  late ResponseCache cache;

  setUp(() {
    db = AppDatabase(DatabaseConnection(NativeDatabase.memory(), closeStreamsSynchronously: true));
    now = DateTime.utc(2026, 11, 12, 10);
    cache = ResponseCache(db, maxEntries: 3, clock: () => now);
  });

  tearDown(() => db.close());

  Stream<Cached<String>> watch(String key, Future<Map<String, Object?>> Function() fetch, {bool force = false}) =>
      cache.watch(key, fetch: fetch, parse: (json) => json['v']! as String, force: force);

  const offline = ApiException(code: ApiException.networkCode, status: 0);

  group('ResponseCache', () {
    test('shows the copy first, then the refreshed answer', () async {
      await watch('k', () async => {'v': 'old'}).drain<void>();
      now = now.add(const Duration(hours: 1));

      final seen = await watch('k', () async => {'v': 'new'}).map((c) => c.data).toList();

      expect(seen, ['old', 'new']);
    });

    test('a recent copy is not fetched again (data costs money)', () async {
      await watch('k', () async => {'v': 'old'}).drain<void>();
      now = now.add(const Duration(minutes: 5));
      var fetched = false;

      final seen = await watch('k', () async {
        fetched = true;
        return {'v': 'new'};
      }).map((c) => c.data).toList();

      expect(seen, ['old']);
      expect(fetched, isFalse);
      // Pull to refresh still asks.
      expect(await watch('k', () async => {'v': 'new'}, force: true).map((c) => c.data).last, 'new');
    });

    test('offline: the copy with a notice, or an error when there is none', () async {
      await watch('k', () async => {'v': 'old'}).drain<void>();
      now = now.add(const Duration(hours: 1));

      final last = await watch('k', () async => throw offline).last;
      expect(last.data, 'old');
      expect(last.isOffline, isTrue);
      expect(last.fetchedAt, DateTime.utc(2026, 11, 12, 10));

      await expectLater(watch('none', () async => throw offline), emitsError(isA<ApiException>()));
    });

    test('a page the API now says is gone is no longer shown from the copy', () async {
      await watch('k', () async => {'v': 'old'}).drain<void>();
      now = now.add(const Duration(hours: 1));
      const gone = ApiException(code: 'resource.not_found', status: 404);

      await expectLater(watch('k', () async => throw gone), emitsInOrder([isA<Cached<String>>(), emitsError(gone)]));
      expect(await db.select(db.cachedResponses).get(), isEmpty);
    });

    test('shows a good answer even when the phone cannot save it', () async {
      final full = ResponseCache(db, clock: () => now);
      await db.close(); // every write now fails
      db = AppDatabase(DatabaseConnection(NativeDatabase.memory(), closeStreamsSynchronously: true));
      expect(
        await full
            .watch('k', fetch: () async => {'v': 'fresh'}, parse: (j) => j['v']! as String)
            .map((c) => c.data)
            .toList(),
        ['fresh'],
      );
    });

    test('a server error with a copy says "could not update", not "offline"', () async {
      await watch('k', () async => {'v': 'old'}).drain<void>();
      now = now.add(const Duration(hours: 1));
      final last = await watch('k', () async => throw const ApiException(code: 'server.error', status: 503)).last;
      expect(last.data, 'old');
      expect(last.isOffline, isFalse);
      expect(last.refreshError?.status, 503);
    });

    test('a copy the app can no longer read is fetched again', () async {
      await cache.write('k', {'unexpected': true});
      expect(await watch('k', () async => {'v': 'fresh'}).map((c) => c.data).toList(), ['fresh']);
    });

    test('keeps the most recently read copies and drops old ones', () async {
      for (final key in ['a', 'b', 'c']) {
        await cache.write(key, {'v': key});
        now = now.add(const Duration(minutes: 1));
      }
      await cache.read('a'); // read again: kept
      now = now.add(const Duration(minutes: 1));
      await cache.write('d', {'v': 'd'});

      final keys = (await db.select(db.cachedResponses).get()).map((r) => r.key).toSet();
      expect(keys, {'a', 'c', 'd'});

      now = now.add(const Duration(days: 31));
      await cache.prune();
      expect(await db.select(db.cachedResponses).get(), isEmpty);
    });
  });

  group('CourseQuery', () {
    test('the same search has the same cache key, whatever the spelling', () {
      expect(const CourseQuery(q: ' Maths ').key(1), const CourseQuery(q: 'maths').key(1));
      expect(const CourseQuery(q: 'maths', price: 'free').key(1), 'courses?q=maths&price=free&page=1');
      expect(const CourseQuery().key(2), 'courses?page=2');
      expect(const CourseQuery(category: 'sciences').isEmpty, isFalse);
    });
  });

  group('Formatters.money', () {
    test('formats minor units per locale, like the web', () {
      expect(Formatters.money(1500, 'USD', 'en'), r'$15.00');
      expect(Formatters.money(1500, 'USD', 'fr').replaceAll(' ', ' ').replaceAll(' ', ' '), r'15,00 $US');
    });

    test('hides centimes on whole francs', () {
      final text = Formatters.money(2500000, 'CDF', 'fr').replaceAll(' ', ' ').replaceAll(' ', ' ');
      expect(text, startsWith('25 000'));
      expect(text, isNot(contains(',')));
      expect(Formatters.money(2500050, 'CDF', 'fr'), contains(',50'));
      expect(Formatters.money(2500000, 'CDF', 'en').replaceAll(' ', ' '), 'CDF 25,000');
    });
  });
}
