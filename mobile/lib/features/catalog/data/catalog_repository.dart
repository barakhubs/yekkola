import '../../../core/api/api_exception.dart';
import '../../../core/api/generated/yekkola_api.dart';
import '../../../core/cache/response_cache.dart';

/// Catalogue search and filters (PRD-04 FR-02). Only the API's values; the API does the filtering.
class CourseQuery {
  const CourseQuery({this.q, this.category, this.price, this.language, this.sort});

  final String? q;

  /// Category slug.
  final String? category;

  /// `free` or `paid`.
  final String? price;

  /// `fr` or `en`.
  final String? language;

  /// `relevance`, `popular`, `newest`, `rating`, `price_asc`, `price_desc`.
  final String? sort;

  bool get isEmpty => (q ?? '').trim().isEmpty && category == null && price == null && language == null;

  CourseQuery copyWith({
    String? q,
    String? category,
    String? price,
    String? language,
    String? sort,
    bool clearCategory = false,
    bool clearPrice = false,
    bool clearLanguage = false,
  }) => CourseQuery(
    q: q ?? this.q,
    category: clearCategory ? null : (category ?? this.category),
    price: clearPrice ? null : (price ?? this.price),
    language: clearLanguage ? null : (language ?? this.language),
    sort: sort ?? this.sort,
  );

  /// Stable cache key (same filters → same key).
  String key(int page) {
    final parts = {
      if ((q ?? '').trim().isNotEmpty) 'q': q!.trim().toLowerCase(),
      'category': ?category,
      'price': ?price,
      'language': ?language,
      'sort': ?sort,
      'page': '$page',
    }.entries.map((e) => '${e.key}=${Uri.encodeQueryComponent(e.value)}');
    return 'courses?${parts.join('&')}';
  }

  @override
  bool operator ==(Object other) =>
      other is CourseQuery &&
      other.q == q &&
      other.category == category &&
      other.price == price &&
      other.language == language &&
      other.sort == sort;

  @override
  int get hashCode => Object.hash(q, category, price, language, sort);
}

/// Catalogue, course and professor pages, offline-first (PRD-04): every read comes from the cache first,
/// then the network. Search result pages after the first are network only (no point keeping them).
class CatalogRepository {
  CatalogRepository(this._api, this._cache);

  final YekkolaApi _api;
  final ResponseCache _cache;

  Stream<Cached<FeaturedResponse200Data>> featured({bool force = false}) => _cache.watch(
    'featured',
    force: force,
    fetch: () async => (await ApiException.guard(() => _api.course.featured())).toJson(),
    parse: (json) => FeaturedResponse200.fromJson(json).data,
  );

  Stream<Cached<List<CategoryResource>>> categories({bool force = false}) => _cache.watch(
    'categories',
    force: force,
    fetch: () async => (await ApiException.guard(() => _api.category.categoriesIndex())).toJson(),
    parse: (json) => CategoriesIndexResponse200.fromJson(json).data.map(_category).toList(),
  );

  Stream<Cached<CourseDetailResource>> course(String slug, {bool force = false}) => _cache.watch(
    'course:$slug',
    force: force,
    fetch: () async => (await ApiException.guard(() => _api.course.coursesShow(slug: slug))).toJson(),
    parse: (json) => CoursesShowResponse200.fromJson(json).data,
  );

  Stream<Cached<PublicProfessorResource>> professor(String slug, {bool force = false}) => _cache.watch(
    'professor:$slug',
    force: force,
    fetch: () async => (await ApiException.guard(() => _api.professor.professorsShow(slug: slug))).toJson(),
    parse: (json) => ProfessorsShowResponse200.fromJson(json).data,
  );

  /// First page: cached. Later pages: network only.
  Stream<Cached<CoursesIndexResponse200>> firstPage(CourseQuery query, {bool force = false}) => _cache.watch(
    query.key(1),
    force: force,
    fetch: () async => (await _search(query, 1)).toJson(),
    parse: CoursesIndexResponse200.fromJson,
  );

  Future<CoursesIndexResponse200> page(CourseQuery query, int page) => _search(query, page);

  Future<CoursesIndexResponse200> _search(CourseQuery query, int page) => ApiException.guard(
    () => _api.course.coursesIndex(
      q: (query.q ?? '').trim().isEmpty ? null : query.q!.trim(),
      category: query.category,
      price: query.price == null ? null : CoursesIndexPrice.fromJson(query.price),
      language: query.language == null ? null : CoursesIndexLanguage.fromJson(query.language),
      sort: query.sort == null ? null : CoursesIndexSort.fromJson(query.sort),
      page: page,
      perPage: 20,
    ),
  );

  /// Top-level list items have CategoryResource's shape; one type for the screens.
  static CategoryResource _category(CategoriesIndexResponse200DataItem item) =>
      CategoryResource.fromJson(item.toJson());
}
