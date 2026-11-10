import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../features/account/presentation/devices_screen.dart';
import '../../features/auth/domain/auth_state.dart';
import '../../features/auth/presentation/auth_controller.dart';
import '../../features/auth/presentation/code_screen.dart';
import '../../features/auth/presentation/device_limit_screen.dart';
import '../../features/auth/presentation/phone_screen.dart';
import '../../features/auth/presentation/profile_screen.dart';
import '../../features/auth/presentation/suspended_screen.dart';
import '../../features/home/presentation/home_screen.dart';

abstract final class Routes {
  static const signIn = '/sign-in';
  static const signInCode = '/sign-in/code';
  static const signInDevices = '/sign-in/devices';
  static const welcome = '/welcome';
  static const suspended = '/suspended';
  static const home = '/home';
  static const devices = '/account/devices';
}

/// Where the session says the user must be, or null to stay. Pure, so it is easy to reason about.
String? authRedirect(AuthState auth, String location) {
  final inSignIn = location.startsWith(Routes.signIn);

  return switch (auth) {
    SignedOut() => inSignIn ? null : Routes.signIn,
    SignedIn(:final user) when user.isSuspended => location == Routes.suspended ? null : Routes.suspended,
    SignedIn(:final user) when user.needsProfile => location == Routes.welcome ? null : Routes.welcome,
    SignedIn() => inSignIn || location == Routes.welcome || location == Routes.suspended ? Routes.home : null,
  };
}

final routerProvider = Provider<GoRouter>((ref) {
  final refresh = ValueNotifier<AuthState>(ref.read(authControllerProvider));
  ref.listen(authControllerProvider, (_, next) => refresh.value = next);
  ref.onDispose(refresh.dispose);

  final router = GoRouter(
    initialLocation: Routes.home,
    refreshListenable: refresh,
    redirect: (context, state) => authRedirect(refresh.value, state.matchedLocation),
    routes: [
      GoRoute(path: Routes.signIn, builder: (_, _) => const PhoneScreen()),
      GoRoute(path: Routes.signInCode, builder: (_, _) => const CodeScreen()),
      GoRoute(path: Routes.signInDevices, builder: (_, _) => const DeviceLimitScreen()),
      GoRoute(path: Routes.welcome, builder: (_, _) => const ProfileScreen()),
      GoRoute(path: Routes.suspended, builder: (_, _) => const SuspendedScreen()),
      GoRoute(path: Routes.home, builder: (_, _) => const HomeScreen()),
      GoRoute(path: Routes.devices, builder: (_, _) => const DevicesScreen()),
    ],
  );
  ref.onDispose(router.dispose);
  return router;
});
