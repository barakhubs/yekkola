import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/api/generated/yekkola_api.dart';
import '../../../core/cache/response_cache.dart';
import '../../../core/i18n/locale_controller.dart';
import '../../../core/providers.dart';
import '../../auth/domain/auth_state.dart';
import '../../auth/presentation/auth_controller.dart';
import '../../catalog/presentation/catalog_providers.dart';
import '../../checkout/presentation/checkout_providers.dart';

/// Pull to refresh on the learning screens (same 30-second "force" window as the catalogue).
class LearningRefresh extends Notifier<DateTime?> {
  @override
  DateTime? build() => null;

  void pull() => state = DateTime.now();
}

final learningRefreshProvider = NotifierProvider<LearningRefresh, DateTime?>(LearningRefresh.new);

bool _recently(DateTime? at) => at != null && DateTime.now().difference(at) < const Duration(seconds: 30);

/// My courses (PRD-06 FR-01), most recently active first, kept per account on the phone (readable offline).
/// Shares its saved copy with the course page's "enrolled?" check.
final myCoursesProvider = StreamProvider.autoDispose<Cached<List<MyCourseResource>>>((ref) {
  final auth = ref.watch(authControllerProvider);
  if (auth is! SignedIn) return const Stream.empty();
  final force = _recently(ref.watch(enrollmentsChangedProvider)) || _recently(ref.watch(learningRefreshProvider));
  final api = ref.watch(yekkolaApiProvider);

  return ref
      .watch(responseCacheProvider)
      .watch(
        'user:${auth.user.id}:enrollments',
        force: force,
        fetch: () async => (await ApiException.guard(() => api.learning.meEnrollmentsIndex(perPage: 100))).toJson(),
        parse: (json) => MeEnrollmentsIndexResponse200.fromJson(json).data,
      );
}, retry: (_, _) => null);

/// One course as its student sees it (PRD-06 FR-02): live curriculum, my status per lesson, where to continue.
final learnCourseProvider = StreamProvider.autoDispose.family<Cached<LearningViewResource>, String>((ref, courseId) {
  final auth = ref.watch(authControllerProvider);
  if (auth is! SignedIn) return const Stream.empty();
  final lang = ref.watch(localeControllerProvider).languageCode;
  final force = _recently(ref.watch(learningRefreshProvider)) || _recently(ref.watch(enrollmentsChangedProvider));
  final api = ref.watch(yekkolaApiProvider);

  return ref
      .watch(responseCacheProvider)
      .watch(
        '$lang:user:${auth.user.id}:learn:$courseId',
        force: force,
        fetch: () async => (await ApiException.guard(() => api.learning.meCoursesShow(course: courseId))).toJson(),
        parse: (json) => MeCoursesShowResponse200.fromJson(json).data,
      );
}, retry: (_, _) => null);
