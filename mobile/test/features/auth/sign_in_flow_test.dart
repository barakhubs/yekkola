import 'package:flutter_test/flutter_test.dart';
import 'package:yekkola/core/storage/secure_store.dart';
import 'package:yekkola/features/auth/domain/auth_state.dart';
import 'package:yekkola/features/auth/presentation/auth_controller.dart';
import 'package:yekkola/features/auth/presentation/sign_in_controller.dart';

import '../../support/fake_api.dart';

const _limitReached = FakeResponse(409, {
  'type': 'about:blank',
  'title': "Nombre maximal d'appareils atteint.",
  'status': 409,
  'code': 'device.limit_reached',
  'limit': 2,
  'devices': [
    {
      'id': '01JDEVICEA0000000000000000',
      'platform': 'android',
      'name': 'Itel A70',
      'last_active_at': '2026-11-09T08:00:00Z',
    },
    {'id': '01JDEVICEB0000000000000000', 'platform': 'ios', 'name': null, 'last_active_at': null},
  ],
});

FakeResponse _signedIn({bool isNew = false, String? name = 'Amani'}) => FakeResponse(200, {
  'data': {
    'user': userJson(name: name),
    'is_new_user': isNew,
    'token': '7|device-token',
    'device': {
      'id': '01JDEVICEC0000000000000000',
      'platform': 'android',
      'name': 'Tecno Spark',
      'app_version': '0.1.0',
      'registered_at': '2026-11-10T10:00:00Z',
      'last_active_at': '2026-11-10T10:00:00Z',
      'is_current': true,
    },
  },
});

const _challenge = FakeResponse(202, {
  'data': {'challenge_id': 'c1', 'expires_at': '2026-11-10T10:05:00Z', 'resend_after_seconds': 60},
});

void main() {
  test('at the device limit, the same code is sent again with the device to replace (PRD-01 FR-08)', () async {
    final api = FakeApi((request, body) {
      if (request.path == '/auth/otp/request') return _challenge;
      return body?['replace_device_id'] == null ? _limitReached : _signedIn();
    });
    final store = MemorySecureStore();
    final container = makeContainer(api, store: store);
    final flow = container.read(signInControllerProvider.notifier);

    expect(await flow.requestCode('0812 345 678'), isTrue);
    expect(api.bodies.last, {'phone': '+243812345678'});

    expect(await flow.verify('123456'), VerifyOutcome.deviceLimit);
    final limit = container.read(signInControllerProvider).deviceLimit!;
    expect(limit.limit, 2);
    expect(limit.devices.map((d) => d.id), ['01JDEVICEA0000000000000000', '01JDEVICEB0000000000000000']);
    expect(container.read(authControllerProvider), isA<SignedOut>());

    expect(await flow.replaceDevice('01JDEVICEA0000000000000000'), VerifyOutcome.signedIn);

    final retry = api.bodies.last!;
    expect(retry['code'], '123456');
    expect(retry['phone'], '+243812345678');
    expect(retry['replace_device_id'], '01JDEVICEA0000000000000000');
    expect(retry['device'], {
      'install_id': 'install-1234',
      'platform': 'android',
      'name': 'Tecno Spark',
      'app_version': '0.1.0',
    });

    final auth = container.read(authControllerProvider);
    expect(auth, isA<SignedIn>());
    expect(auth.token, '7|device-token');
    expect(store.values['auth_token'], '7|device-token');
    // The code is forgotten once used.
    expect(container.read(signInControllerProvider).code, isNull);
  });

  test('a wrong code keeps the user signed out and reports the attempts left', () async {
    final api = FakeApi(
      (request, _) => request.path == '/auth/otp/request'
          ? _challenge
          : const FakeResponse(422, {
              'status': 422,
              'code': 'otp.invalid',
              'title': 'Code incorrect.',
              'attempts_remaining': 3,
            }),
    );
    final container = makeContainer(api);
    final flow = container.read(signInControllerProvider.notifier);

    await flow.requestCode('+243812345678');
    expect(await flow.verify('000000'), VerifyOutcome.failed);

    final error = container.read(signInControllerProvider).error!;
    expect(error.code, 'otp.invalid');
    expect(error.context['attempts_remaining'], 3);
    expect(container.read(authControllerProvider), isA<SignedOut>());
  });

  test('a first sign-in without a name goes to the profile step (PRD-01 FR-04)', () async {
    final api = FakeApi(
      (request, _) => request.path == '/auth/otp/request' ? _challenge : _signedIn(isNew: true, name: null),
    );
    final container = makeContainer(api);
    final flow = container.read(signInControllerProvider.notifier);

    await flow.requestCode('0812345678');
    expect(await flow.verify('123456'), VerifyOutcome.newUser);
    expect((container.read(authControllerProvider) as SignedIn).user.needsProfile, isTrue);
  });

  test('rate limits carry the wait from the API', () async {
    final api = FakeApi(
      (_, _) =>
          const FakeResponse(429, {'status': 429, 'code': 'otp.cooldown', 'retry_after': 42}, {'retry-after': '42'}),
    );
    final container = makeContainer(api);

    expect(await container.read(signInControllerProvider.notifier).requestCode('0812345678'), isFalse);
    expect(container.read(signInControllerProvider).error!.retryAfter, 42);
  });

  group('normalizePhoneInput', () {
    test('national DRC numbers get +243 without the trunk 0', () {
      expect(normalizePhoneInput('0812 345 678'), '+243812345678');
      expect(normalizePhoneInput('812-345-678'), '+243812345678');
    });

    test('international forms are kept', () {
      expect(normalizePhoneInput('+243 812 345 678'), '+243812345678');
      expect(normalizePhoneInput('00243812345678'), '+243812345678');
      expect(normalizePhoneInput('243812345678'), '+243812345678');
      expect(normalizePhoneInput('+256 772 123 456'), '+256772123456');
    });
  });
}
