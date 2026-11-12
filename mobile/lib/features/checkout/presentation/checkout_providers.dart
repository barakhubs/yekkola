import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/api/generated/yekkola_api.dart';
import '../../../core/cache/response_cache.dart';
import '../../../core/providers.dart';
import '../../../core/sync/sync_providers.dart';
import '../../auth/domain/auth_state.dart';
import '../../auth/presentation/auth_controller.dart';
import '../../catalog/presentation/catalog_providers.dart';
import '../data/checkout_repository.dart';

final checkoutRepositoryProvider = Provider<CheckoutRepository>(
  (ref) => CheckoutRepository(ref.watch(yekkolaApiProvider), ref.watch(dioProvider), ref.watch(appDatabaseProvider)),
);

/// Bumped after an enrolment or a confirmed payment: the "my courses" list is fetched again.
class EnrollmentsChanged extends Notifier<DateTime?> {
  @override
  DateTime? build() => null;

  void changed() => state = DateTime.now();
}

final enrollmentsChangedProvider = NotifierProvider<EnrollmentsChanged, DateTime?>(EnrollmentsChanged.new);

/// The ids of the courses this account is enrolled in (the course page's button), kept per account on the
/// phone so it is known offline too.
final myCourseIdsProvider = StreamProvider.autoDispose<Cached<Set<String>>>((ref) {
  final auth = ref.watch(authControllerProvider);
  if (auth is! SignedIn) return const Stream.empty();
  final changed = ref.watch(enrollmentsChangedProvider);
  final force = changed != null && DateTime.now().difference(changed) < const Duration(seconds: 30);
  final api = ref.watch(yekkolaApiProvider);

  return ref
      .watch(responseCacheProvider)
      .watch(
        'user:${auth.user.id}:enrollments',
        force: force,
        fetch: () async => (await ApiException.guard(() => api.learning.meEnrollmentsIndex(perPage: 100))).toJson(),
        parse: (json) => MeEnrollmentsIndexResponse200.fromJson(json).data.map((e) => e.course.id).toSet(),
      );
}, retry: (_, _) => null);
