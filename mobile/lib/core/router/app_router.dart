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
import '../../features/catalog/data/catalog_repository.dart';
import '../../features/catalog/presentation/course_screen.dart';
import '../../features/catalog/presentation/explore_screen.dart';
import '../../features/catalog/presentation/professor_screen.dart';
import '../../features/catalog/presentation/search_screen.dart';
import '../../features/checkout/presentation/checkout_screen.dart';
import '../../features/checkout/presentation/order_screen.dart';
import '../../features/home/presentation/home_screen.dart';
import '../../features/learning/presentation/learn_course_screen.dart';
import '../../features/learning/presentation/lesson_player_screen.dart';
import '../../features/learning/presentation/my_courses_screen.dart';
import '../../features/media/presentation/downloads_screen.dart';
import '../../features/media/presentation/offline_player_screen.dart';

abstract final class Routes {
  static const signIn = '/sign-in';
  static const signInCode = '/sign-in/code';
  static const signInDevices = '/sign-in/devices';
  static const welcome = '/welcome';
  static const suspended = '/suspended';

  /// Landing after sign-in: discovery.
  static const explore = '/explore';
  static const search = '/explore/search';
  static String course(String slug) => '/courses/$slug';
  static String professor(String slug) => '/professors/$slug';
  static String checkout(String courseId) => '/checkout/$courseId';
  static String order(String orderId) => '/orders/$orderId';
  static String learn(String courseId) => '/learn/$courseId';
  static String lesson(String courseId, String lessonUid) => '/learn/$courseId/lessons/$lessonUid';

  /// My courses.
  static const home = '/home';
  static const devices = '/account/devices';
  static const downloads = '/downloads';
  static String offlinePlayer(String lessonUid) => '/downloads/$lessonUid';
}

/// Where the session says the user must be, or null to stay. Pure, so it is easy to reason about.
String? authRedirect(AuthState auth, String location) {
  final inSignIn = location.startsWith(Routes.signIn);

  return switch (auth) {
    SignedOut() => inSignIn ? null : Routes.signIn,
    SignedIn(:final user) when user.isSuspended => location == Routes.suspended ? null : Routes.suspended,
    SignedIn(:final user) when user.needsProfile => location == Routes.welcome ? null : Routes.welcome,
    SignedIn() => inSignIn || location == Routes.welcome || location == Routes.suspended ? Routes.explore : null,
  };
}

final routerProvider = Provider<GoRouter>((ref) {
  final refresh = ValueNotifier<AuthState>(ref.read(authControllerProvider));
  ref.listen(authControllerProvider, (_, next) => refresh.value = next);
  ref.onDispose(refresh.dispose);

  final router = GoRouter(
    initialLocation: Routes.explore,
    refreshListenable: refresh,
    redirect: (context, state) => authRedirect(refresh.value, state.matchedLocation),
    routes: [
      GoRoute(path: Routes.signIn, builder: (_, _) => const PhoneScreen()),
      GoRoute(path: Routes.signInCode, builder: (_, _) => const CodeScreen()),
      GoRoute(path: Routes.signInDevices, builder: (_, _) => const DeviceLimitScreen()),
      GoRoute(path: Routes.welcome, builder: (_, _) => const ProfileScreen()),
      GoRoute(path: Routes.suspended, builder: (_, _) => const SuspendedScreen()),
      StatefulShellRoute.indexedStack(
        builder: (context, state, shell) =>
            TabsShell(selectedIndex: shell.currentIndex, onSelect: shell.goBranch, child: shell),
        branches: [
          StatefulShellBranch(
            routes: [GoRoute(path: Routes.explore, builder: (_, _) => const ExploreScreen())],
          ),
          StatefulShellBranch(
            routes: [GoRoute(path: Routes.home, builder: (_, _) => const MyCoursesScreen())],
          ),
        ],
      ),
      GoRoute(
        path: Routes.search,
        builder: (_, state) =>
            SearchScreen(initial: state.extra is CourseQuery ? state.extra! as CourseQuery : const CourseQuery()),
      ),
      GoRoute(
        path: '/courses/:slug',
        builder: (_, state) => CourseScreen(slug: state.pathParameters['slug']!),
      ),
      GoRoute(
        path: '/professors/:slug',
        builder: (_, state) => ProfessorScreen(slug: state.pathParameters['slug']!),
      ),
      GoRoute(
        path: '/checkout/:course',
        builder: (_, state) => CheckoutScreen(courseId: state.pathParameters['course']!),
      ),
      GoRoute(
        path: '/orders/:order',
        builder: (_, state) => OrderScreen(orderId: state.pathParameters['order']!),
      ),
      GoRoute(
        path: '/learn/:course',
        builder: (_, state) => LearnCourseScreen(courseId: state.pathParameters['course']!),
        routes: [
          GoRoute(
            path: 'lessons/:lesson',
            builder: (_, state) => LessonPlayerScreen(
              lessonUid: state.pathParameters['lesson']!,
              args: state.extra is LessonArgs ? state.extra! as LessonArgs : const LessonArgs(),
            ),
          ),
        ],
      ),
      GoRoute(path: Routes.devices, builder: (_, _) => const DevicesScreen()),
      GoRoute(
        path: Routes.downloads,
        builder: (_, _) => const DownloadsScreen(),
        routes: [
          GoRoute(
            path: ':lesson',
            builder: (_, state) =>
                OfflinePlayerScreen(lessonUid: state.pathParameters['lesson']!, title: state.extra as String?),
          ),
        ],
      ),
    ],
  );
  ref.onDispose(router.dispose);
  return router;
});
