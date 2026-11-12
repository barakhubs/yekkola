import 'package:drift/drift.dart' hide isNull, isNotNull;
import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:yekkola/core/api/api_exception.dart';
import 'package:yekkola/core/api/generated/yekkola_api.dart';
import 'package:yekkola/core/db/app_database.dart';
import 'package:yekkola/core/providers.dart';
import 'package:yekkola/features/auth/domain/app_user.dart';
import 'package:yekkola/features/auth/domain/auth_state.dart';
import 'package:yekkola/features/checkout/data/checkout_repository.dart';
import 'package:yekkola/features/checkout/data/idempotency.dart';
import 'package:yekkola/features/checkout/domain/checkout_state.dart';

import '../../support/fake_api.dart';

const _course = '01JCOURSE00000000000000000';

Map<String, Object?> _money(int minor) => {'amount_minor': minor, 'currency': 'USD'};

Map<String, Object?> _order({
  String status = 'pending',
  List<Map<String, Object?>> payments = const [],
  String expiresAt = '2099-01-01T00:00:00Z',
}) => {
  'id': '01JORDER000000000000000000',
  'number': 'YK-1001',
  'status': status,
  'currency': 'USD',
  'subtotal': _money(1500),
  'discount': _money(0),
  'total': _money(1500),
  'expires_at': expiresAt,
  'paid_at': null,
  'created_at': '2026-11-12T10:00:00Z',
  'is_gift': false,
  'items': [
    {
      'id': 'i1',
      'course_id': _course,
      'course_slug': 'algebre',
      'title': 'Algèbre',
      'list_price': _money(1500),
      'discount': _money(0),
      'price': _money(1500),
      'refund': {'status': null, 'can_request': false},
    },
  ],
  'payments': payments,
};

Map<String, Object?> _payment(String status) => {
  'id': 'p-$status',
  'order_id': '01JORDER000000000000000000',
  'rail': 'orange',
  'payer': '+243812345678',
  'amount': _money(1500),
  'status': status,
  'failure_code': status == 'failed' ? 'insufficient_funds' : null,
  'failure_reason': status == 'failed' ? 'Solde insuffisant.' : null,
  'operator_reference': null,
  'created_at': '2026-11-12T10:01:00Z',
};

void main() {
  late AppDatabase db;

  setUp(() => db = AppDatabase(DatabaseConnection(NativeDatabase.memory(), closeStreamsSynchronously: true)));
  tearDown(() => db.close());

  CheckoutRepository repo(FakeApi api) {
    final container = makeContainer(
      api,
      initial: SignedIn(user: AppUser.fromJson(userJson()), token: 't'),
    );
    return CheckoutRepository(container.read(yekkolaApiProvider), container.read(dioProvider), db);
  }

  group('IdempotencyKeys', () {
    test('the same action keeps its key across retries and restarts until a final answer', () async {
      final keys = IdempotencyKeys(db);
      final seen = <String>[];

      // Lost on the network, then a 503: both retry with the same key.
      for (final failure in [
        const ApiException(code: ApiException.networkCode, status: 0),
        const ApiException(code: 'server.error', status: 503),
        const ApiException(code: 'request.busy', status: 409),
      ]) {
        await expectLater(
          keys.run('order:a', (key) async {
            seen.add(key);
            throw failure;
          }),
          throwsA(failure),
        );
      }
      // The app restarts: a new store over the same database still has it.
      final again = await IdempotencyKeys(db).run('order:a', (key) async => key);

      expect(seen.toSet(), hasLength(1));
      expect(again, seen.first);
      // Success: the next order is a new action.
      expect(await IdempotencyKeys(db).keyFor('order:a'), isNot(seen.first));
    });

    test('a key older than a day is not reused', () async {
      var now = DateTime.utc(2026, 11, 12, 10);
      final keys = IdempotencyKeys(db, clock: () => now);
      final first = await keys.keyFor('order:c');
      now = now.add(const Duration(hours: 23));
      expect(await keys.keyFor('order:c'), first);
      now = now.add(const Duration(hours: 2));
      expect(await keys.keyFor('order:c'), isNot(first));
    });

    test('a final refusal (422) forgets the key', () async {
      final keys = IdempotencyKeys(db);
      late String first;
      await expectLater(
        keys.run('order:b', (key) async {
          first = key;
          throw const ApiException(code: 'validation.failed', status: 422);
        }),
        throwsA(isA<ApiException>()),
      );
      expect(await keys.keyFor('order:b'), isNot(first));
    });
  });

  group('CheckoutRepository', () {
    test('creates the order with an Idempotency-Key and only course_ids, and remembers it as open', () async {
      var calls = 0;
      final api = FakeApi((request, _) {
        calls++;
        // The first answer is lost: the retry must reuse the key.
        if (calls == 1) throw StateError('connection reset');
        return FakeResponse(201, {'data': _order()});
      });
      final checkout = repo(api);

      await expectLater(checkout.orderFor(userId: 'u', courseId: _course), throwsA(isA<ApiException>()));
      final order = (await checkout.orderFor(userId: 'u', courseId: _course)).order;

      expect(order.id, '01JORDER000000000000000000');
      final keys = api.requests.map((r) => r.headers['Idempotency-Key']).toList();
      expect(keys[0], isNotNull);
      expect(keys[1], keys[0]);
      expect(api.bodies.last, {
        'course_ids': [_course],
      });
      expect(await checkout.openOrder('u', _course), order.id);
    });

    test('the API refusing a second open order resumes that order (order.open_order_exists)', () async {
      final api = FakeApi(
        (request, _) => request.path == '/orders'
            ? const FakeResponse(409, {
                'status': 409,
                'code': 'order.open_order_exists',
                'order_id': '01JORDER000000000000000000',
              })
            : FakeResponse(200, {
                'data': _order(payments: [_payment('failed')]),
              }),
      );
      final checkout = repo(api);

      final result = await checkout.orderFor(userId: 'u', courseId: _course, coupon: 'PROMO');

      expect(result.order.id, '01JORDER000000000000000000');
      expect(result.keptOpenOrder, isTrue);
      expect(await checkout.openOrder('u', _course), '01JORDER000000000000000000');
    });

    test('an order still open is reused (even with another coupon); offline it is kept, not forgotten', () async {
      var offline = false;
      final api = FakeApi((request, _) {
        if (offline) throw StateError('no network');
        if (request.path == '/orders') return FakeResponse(201, {'data': _order()});
        return FakeResponse(200, {
          'data': _order(payments: [_payment('failed')]),
        });
      });
      final checkout = repo(api);
      await checkout.rememberOpenOrder('u', _course, '01JORDER000000000000000000');

      final result = await checkout.orderFor(userId: 'u', courseId: _course, coupon: 'PROMO');
      expect(result.keptOpenOrder, isTrue);
      expect(api.requests.where((r) => r.path == '/orders'), isEmpty);

      offline = true;
      await expectLater(checkout.orderFor(userId: 'u', courseId: _course), throwsA(isA<ApiException>()));
      expect(await checkout.openOrder('u', _course), '01JORDER000000000000000000');
    });

    test('an old key bringing back an expired order is dropped and the order created again', () async {
      var created = 0;
      final api = FakeApi((request, _) {
        created++;
        return FakeResponse(201, {'data': created == 1 ? _order(status: 'expired') : _order()});
      });
      final checkout = repo(api);

      final result = await checkout.orderFor(userId: 'u', courseId: _course);

      expect(result.order.status, 'pending');
      final keys = api.requests.map((r) => r.headers['Idempotency-Key']).toList();
      expect(keys, hasLength(2));
      expect(keys[1], isNot(keys[0]));
    });

    test('a new payment attempt gets a new key; a lost request keeps its own', () async {
      final api = FakeApi((_, _) => FakeResponse(201, {'data': _payment('pending')}));
      final checkout = repo(api);
      final first = OrderResource.fromJson(_order());
      final afterFailure = OrderResource.fromJson(_order(payments: [_payment('failed')]));

      await checkout.pay(first, rail: 'orange', msisdn: '+243812345678');
      await checkout.pay(afterFailure, rail: 'airtel');

      final keys = api.requests.map((r) => r.headers['Idempotency-Key']).toList();
      expect(keys[0], isNot(keys[1]));
      expect(api.bodies.first, {'rail': 'orange', 'msisdn': '+243812345678'});
    });
  });

  group('stateOf', () {
    OrderResource order(Map<String, Object?> json) => OrderResource.fromJson(json);

    test('reads the order and its latest payment, never assuming success', () {
      expect(stateOf(order(_order())), CheckoutState.choose);
      expect(
        stateOf(order(_order(status: 'awaiting_payment', payments: [_payment('pending')]))),
        CheckoutState.waiting,
      );
      expect(stateOf(order(_order(payments: [_payment('initiated')]))), CheckoutState.waiting);
      expect(stateOf(order(_order(payments: [_payment('failed')]))), CheckoutState.failed);
      expect(stateOf(order(_order(status: 'paid', payments: [_payment('succeeded')]))), CheckoutState.paid);
      expect(stateOf(order(_order(status: 'expired'))), CheckoutState.expired);
      // A payment reported succeeded while the order isn't paid yet: still waiting for the server.
      expect(stateOf(order(_order(payments: [_payment('succeeded')]))), CheckoutState.waiting);
    });

    test("can still pay is the API's status, never the phone's clock", () {
      // Deadline long past by the phone's clock, but the API still says pending: payable.
      expect(CheckoutRepository.canBePaid(order(_order(expiresAt: '2000-01-01T00:00:00Z'))), isTrue);
      expect(CheckoutRepository.canBePaid(order(_order(status: 'awaiting_payment'))), isTrue);
      expect(CheckoutRepository.canBePaid(order(_order(status: 'expired'))), isFalse);
      expect(CheckoutRepository.canBePaid(order(_order(status: 'paid'))), isFalse);
      // A refund is not "you now have access".
      expect(stateOf(order(_order(status: 'refunded'))), CheckoutState.refunded);
    });

    test('polls quickly at first, then gently', () {
      expect(pollDelay(0), const Duration(seconds: 2));
      expect(pollDelay(4), const Duration(seconds: 5));
      expect(pollDelay(50), const Duration(seconds: 8));
    });
  });
}
