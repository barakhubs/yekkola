import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/api/generated/yekkola_api.dart';
import '../../../core/cache/response_cache.dart';
import '../../../core/i18n/locale_controller.dart';
import '../../../core/providers.dart';
import '../../../core/sync/sync_providers.dart';
import '../data/catalog_repository.dart';

final responseCacheProvider = Provider<ResponseCache>((ref) => ResponseCache(ref.watch(appDatabaseProvider)));

final catalogRepositoryProvider = Provider<CatalogRepository>(
  (ref) => CatalogRepository(ref.watch(yekkolaApiProvider), ref.watch(responseCacheProvider)),
);

// Errors are shown by the screens (offline state); Riverpod must not retry them on its own.
Duration? _noRetry(int _, Object _) => null;

/// Pull to refresh: the time of the last pull. Providers alive then rebuild and ask the network again;
/// pages opened later still respect the cache's freshness (data costs money).
class CatalogRefresh extends Notifier<DateTime?> {
  @override
  DateTime? build() => null;

  void pull() => state = DateTime.now();
}

final catalogRefreshProvider = NotifierProvider<CatalogRefresh, DateTime?>(CatalogRefresh.new);

/// The UI language (copies are kept per language) and whether a pull just asked for fresh data.
(String, bool) _context(Ref ref) {
  final pulled = ref.watch(catalogRefreshProvider);
  final force = pulled != null && DateTime.now().difference(pulled) < const Duration(seconds: 30);
  return (ref.watch(localeControllerProvider).languageCode, force);
}

final featuredProvider = StreamProvider.autoDispose<Cached<FeaturedResponse200Data>>((ref) {
  final (lang, force) = _context(ref);
  return ref.watch(catalogRepositoryProvider).featured(lang, force: force);
}, retry: _noRetry);

final categoriesProvider = StreamProvider.autoDispose<Cached<List<CategoryResource>>>((ref) {
  final (lang, force) = _context(ref);
  return ref.watch(catalogRepositoryProvider).categories(lang, force: force);
}, retry: _noRetry);

final courseProvider = StreamProvider.autoDispose.family<Cached<CourseDetailResource>, String>((ref, slug) {
  final (lang, force) = _context(ref);
  return ref.watch(catalogRepositoryProvider).course(slug, lang, force: force);
}, retry: _noRetry);

final professorProvider = StreamProvider.autoDispose.family<Cached<PublicProfessorResource>, String>((ref, slug) {
  final (lang, force) = _context(ref);
  return ref.watch(catalogRepositoryProvider).professor(slug, lang, force: force);
}, retry: _noRetry);

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
    this.loadMoreError,
    this.settled = false,
    this.fetchedAt,
  });

  final List<CourseCardResource> courses;
  final int total;
  final int lastPage;
  final int page;
  final bool loading;
  final bool loadingMore;
  final ApiException? error;

  /// The last "load more" failed: no automatic retry; the list shows a retry tile.
  final ApiException? loadMoreError;

  /// Page 1 is final (no refresh still on its way): only then can more pages load.
  final bool settled;

  /// When the first page was fetched (an older copy while offline).
  final DateTime? fetchedAt;

  bool get hasMore => page < lastPage;

  bool get canLoadMore => settled && !loading && !loadingMore && hasMore && loadMoreError == null;

  SearchResults copyWith({
    List<CourseCardResource>? courses,
    int? total,
    int? lastPage,
    int? page,
    bool? loading,
    bool? loadingMore,
    ApiException? error,
    bool clearError = false,
    ApiException? loadMoreError,
    bool clearLoadMoreError = false,
    bool? settled,
    DateTime? fetchedAt,
  }) => SearchResults(
    courses: courses ?? this.courses,
    total: total ?? this.total,
    lastPage: lastPage ?? this.lastPage,
    page: page ?? this.page,
    loading: loading ?? this.loading,
    loadingMore: loadingMore ?? this.loadingMore,
    error: clearError ? null : (error ?? this.error),
    loadMoreError: clearLoadMoreError ? null : (loadMoreError ?? this.loadMoreError),
    settled: settled ?? this.settled,
    fetchedAt: fetchedAt ?? this.fetchedAt,
  );
}

class SearchController extends Notifier<SearchResults> {
  SearchController(this.query);

  final CourseQuery query;

  @override
  SearchResults build() {
    final (lang, force) = _context(ref);
    final subscription = ref
        .read(catalogRepositoryProvider)
        .firstPage(query, lang, force: force)
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
          onDone: () => state = state.copyWith(settled: true),
        );
    ref.onDispose(subscription.cancel);
    return const SearchResults();
  }

  /// Next page on scroll. After a failure it waits for [retryMore] (no request per scroll frame).
  Future<void> loadMore() async {
    if (!state.canLoadMore) return;
    state = state.copyWith(loadingMore: true);
    try {
      final next = await ref.read(catalogRepositoryProvider).page(query, state.page + 1);
      if (!ref.mounted) return;
      state = state.copyWith(
        courses: [...state.courses, ...next.data],
        page: next.meta.currentPage,
        lastPage: next.meta.lastPage,
        loadingMore: false,
      );
    } on ApiException catch (e) {
      if (!ref.mounted) return;
      state = state.copyWith(loadingMore: false, loadMoreError: e);
    }
  }

  Future<void> retryMore() {
    state = state.copyWith(clearLoadMoreError: true);
    return loadMore();
  }
}

final searchProvider = NotifierProvider.autoDispose.family<SearchController, SearchResults, CourseQuery>(
  SearchController.new,
);
