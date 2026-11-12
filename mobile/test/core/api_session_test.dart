import 'package:flutter_test/flutter_test.dart';
import 'package:yekkola/core/router/app_router.dart';
import 'package:yekkola/core/storage/secure_store.dart';
import 'package:yekkola/features/auth/domain/app_user.dart';
import 'package:yekkola/features/auth/domain/auth_state.dart';
import 'package:yekkola/features/auth/presentation/auth_controller.dart';

import '../support/fake_api.dart';

AppUser _user({String? name = 'Amani', String status = 'active'}) =>
    AppUser.fromJson(userJson(name: name, status: status));

void main() {
  test('every request carries X-Device-Id and the UI language; signed-in requests the bearer token', () async {
    final api = FakeApi((_, _) => FakeResponse(200, {'data': userJson()}));
    final signedOut = makeContainer(api);
    await signedOut.read(authRepositoryProvider).fetchMe().catchError((_) => _user());

    final anonymous = api.requests.last.headers;
    expect(anonymous['X-Device-Id'], 'install-1234');
    expect(anonymous['Accept-Language'], 'fr');
    expect(anonymous['X-Request-Id'], isNotEmpty);
    expect(anonymous.containsKey('Authorization'), isFalse);

    final signedIn = makeContainer(
      api,
      initial: SignedIn(user: _user(), token: '7|abc'),
    );
    await signedIn.read(authRepositoryProvider).fetchMe();
    expect(api.requests.last.headers['Authorization'], 'Bearer 7|abc');
    expect(api.requests.last.headers['X-Device-Id'], 'install-1234');
  });

  test('a 401 for the current token ends the session and forgets it', () async {
    final store = MemorySecureStore({'auth_token': '7|abc', 'auth_user': '{}'});
    final api = FakeApi((_, _) => const FakeResponse(401, {'status': 401, 'code': 'auth.unauthenticated'}));
    final container = makeContainer(
      api,
      store: store,
      initial: SignedIn(user: _user(), token: '7|abc'),
    );

    await container.read(authControllerProvider.notifier).refreshUser();
    await Future<void>.delayed(Duration.zero);

    final auth = container.read(authControllerProvider);
    expect(auth, isA<SignedOut>());
    expect((auth as SignedOut).sessionExpired, isTrue);
    expect(store.values.containsKey('auth_token'), isFalse);
  });

  test('offline, the cached session stays', () async {
    final container = makeContainer(
      FakeApi((_, _) => throw StateError('unreachable')),
      initial: SignedIn(user: _user(), token: '7|abc'),
    );

    await container.read(authControllerProvider.notifier).refreshUser();
    expect(container.read(authControllerProvider), isA<SignedIn>());
  });

  test('a ban (403 account.banned, token deleted by the API) ends the session', () async {
    final api = FakeApi((_, _) => const FakeResponse(403, {'status': 403, 'code': 'account.banned'}));
    final container = makeContainer(
      api,
      initial: SignedIn(user: _user(), token: '7|abc'),
    );

    await container.read(authControllerProvider.notifier).refreshUser();
    await Future<void>.delayed(Duration.zero);

    expect(container.read(authControllerProvider), isA<SignedOut>());
  });

  test('a suspension seen on any call refreshes the user, which leads to the suspended screen', () async {
    final api = FakeApi(
      (request, _) => request.path == '/me'
          ? FakeResponse(200, {'data': userJson(status: 'suspended')})
          : const FakeResponse(403, {'status': 403, 'code': 'account.suspended'}),
    );
    final container = makeContainer(
      api,
      initial: SignedIn(user: _user(), token: '7|abc'),
    );

    await expectLater(container.read(authRepositoryProvider).devices(), throwsA(anything));
    // The refresh runs in the background: wait for it (not a fixed delay, which flakes on a busy machine).
    for (var i = 0; i < 200; i++) {
      final current = container.read(authControllerProvider);
      if (current is SignedIn && current.user.isSuspended) break;
      await Future<void>.delayed(const Duration(milliseconds: 10));
    }

    final auth = container.read(authControllerProvider) as SignedIn;
    expect(auth.user.isSuspended, isTrue);
    expect(authRedirect(auth, Routes.home), Routes.suspended);
  });

  test('a slow user refresh does not land in a newer session', () async {
    final api = FakeApi((_, _) => FakeResponse(200, {'data': userJson(name: 'Account A')}));
    final container = makeContainer(
      api,
      initial: SignedIn(user: _user(), token: 'token-a'),
    );
    final auth = container.read(authControllerProvider.notifier);

    final refresh = auth.refreshUser();
    // Meanwhile: signed out and in with another account.
    container.read(authControllerProvider.notifier).state = SignedIn(
      user: _user(name: 'B'),
      token: 'token-b',
    );
    await refresh;

    final now = container.read(authControllerProvider) as SignedIn;
    expect(now.token, 'token-b');
    expect(now.user.name, 'B');
  });

  test('sign-out forgets the session at once and revokes the token in the background', () async {
    final store = MemorySecureStore({'auth_token': '7|abc', 'auth_user': '{}'});
    final api = FakeApi((_, _) => const FakeResponse(204));
    final container = makeContainer(
      api,
      store: store,
      initial: SignedIn(user: _user(), token: '7|abc'),
    );

    await container.read(authControllerProvider.notifier).signOut();
    expect(container.read(authControllerProvider), isA<SignedOut>());
    expect(store.values, isEmpty);

    await Future<void>.delayed(const Duration(milliseconds: 10));
    expect(api.requests.last.path, '/auth/logout');
    expect(api.requests.last.headers['Authorization'], 'Bearer 7|abc');
  });

  group('authRedirect', () {
    test('signed out: only the sign-in screens', () {
      expect(authRedirect(const SignedOut(), Routes.home), Routes.signIn);
      expect(authRedirect(const SignedOut(), Routes.devices), Routes.signIn);
      expect(authRedirect(const SignedOut(), Routes.signInCode), isNull);
    });

    test('suspended accounts only see the explanation (PRD-01 FR-15)', () {
      final suspended = SignedIn(
        user: _user(status: 'suspended'),
        token: 't',
      );
      expect(authRedirect(suspended, Routes.home), Routes.suspended);
      expect(authRedirect(suspended, Routes.devices), Routes.suspended);
      expect(authRedirect(suspended, Routes.suspended), isNull);
    });

    test('a missing name is collected first, then sign-in screens lead home', () {
      expect(authRedirect(SignedIn(user: _user(name: null), token: 't'), Routes.home), Routes.welcome);
      final active = SignedIn(user: _user(), token: 't');
      expect(authRedirect(active, Routes.signInCode), Routes.explore);
      expect(authRedirect(active, Routes.welcome), Routes.explore);
      expect(authRedirect(active, Routes.devices), isNull);
    });
  });
}
