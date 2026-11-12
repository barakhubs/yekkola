import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/api/generated/yekkola_api.dart';
import '../../../core/cache/response_cache.dart';
import '../../../core/providers.dart';
import '../../../core/sync/sync_providers.dart';
import '../data/catalog_repository.dart';

final responseCacheProvider = Provider<ResponseCache>((ref) => ResponseCache(ref.watch(appDatabaseProvider)));

final catalogRepositoryProvider = Provider<CatalogRepository>(
  (ref) => CatalogRepository(ref.watch(yekkolaApiProvider), ref.watch(responseCacheProvider)),
);

// Errors are shown by the screens (offline state); Riverpod must not retry them on its own.
Duration? _noRetry(int _, Object _) => null;

final featuredProvider = StreamProvider.autoDispose<Cached<FeaturedResponse200Data>>(
  (ref) => ref.watch(catalogRepositoryProvider).featured(),
  retry: _noRetry,
);

final categoriesProvider = StreamProvider.autoDispose<Cached<List<CategoryResource>>>(
  (ref) => ref.watch(catalogRepositoryProvider).categories(),
  retry: _noRetry,
);

final courseProvider = StreamProvider.autoDispose.family<Cached<CourseDetailResource>, String>(
  (ref, slug) => ref.watch(catalogRepositoryProvider).course(slug),
  retry: _noRetry,
);

final professorProvider = StreamProvider.autoDispose.family<Cached<PublicProfessorResource>, String>(
  (ref, slug) => ref.watch(catalogRepositoryProvider).professor(slug),
  retry: _noRetry,
);

/// Search results: page 1 from the cache then the network, further pages on scroll.
class SearchResults {
  const SearchResults({
    this.courses = const [],
    this.total = 0,
    this.lastPage = 1,
    this.page = 0,
    this.loading = true,
    this.loadingMore = false,
    this.error,
    this.fetchedAt,
  });

  final List<CourseCardResource> courses;
  final int total;
  final int lastPage;
  final int page;
  final bool loading;
  final bool loadingMore;
  final ApiException? error;

  /// When the first page was fetched (an older copy while offline).
  final DateTime? fetchedAt;

  bool get hasMore => page < lastPage;

  SearchResults copyWith({
    List<CourseCardResource>? courses,
    int? total,
    int? lastPage,
    int? page,
    bool? loading,
    bool? loadingMore,
    ApiException? error,
    bool clearError = false,
    DateTime? fetchedAt,
  }) => SearchResults(
    courses: courses ?? this.courses,
    total: total ?? this.total,
    lastPage: lastPage ?? this.lastPage,
    page: page ?? this.page,
    loading: loading ?? this.loading,
    loadingMore: loadingMore ?? this.loadingMore,
    error: clearError ? null : (error ?? this.error),
    fetchedAt: fetchedAt ?? this.fetchedAt,
  );
}

class SearchController extends Notifier<SearchResults> {
  SearchController(this.query);

  final CourseQuery query;

  @override
  SearchResults build() {
    final subscription = ref
        .read(catalogRepositoryProvider)
        .firstPage(query)
        .listen(
          (cached) => state = state.copyWith(
            courses: cached.data.data,
            total: cached.data.meta.total,
            lastPage: cached.data.meta.lastPage,
            page: 1,
            loading: false,
            fetchedAt: cached.fetchedAt,
            error: cached.refreshError,
            clearError: cached.refreshError == null,
          ),
          onError: (Object e) => state = state.copyWith(
            loading: false,
            error: e is ApiException ? e : const ApiException(code: 'http.0', status: 0),
          ),
        );
    ref.onDispose(subscription.cancel);
    return const SearchResults();
  }

  Future<void> loadMore() async {
    if (state.loading || state.loadingMore || !state.hasMore) return;
    state = state.copyWith(loadingMore: true, clearError: true);
    try {
      final next = await ref.read(catalogRepositoryProvider).page(query, state.page + 1);
      state = state.copyWith(
        courses: [...state.courses, ...next.data],
        page: next.meta.currentPage,
        lastPage: next.meta.lastPage,
        loadingMore: false,
      );
    } on ApiException catch (e) {
      state = state.copyWith(loadingMore: false, error: e);
    }
  }
}

final searchProvider = NotifierProvider.autoDispose.family<SearchController, SearchResults, CourseQuery>(
  SearchController.new,
);
