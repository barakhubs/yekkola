import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/api/generated/yekkola_api.dart';
import '../../../core/router/app_router.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../home/presentation/account_menu.dart';
import '../data/catalog_repository.dart';
import 'catalog_providers.dart';
import 'widgets/catalog_widgets.dart';

/// Discovery (PRD-04 FR-01–03): search, categories and the featured shelves. Read from the phone's copy
/// first, refreshed online; pull to refresh.
class ExploreScreen extends ConsumerWidget {
  const ExploreScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context);
    final featured = ref.watch(featuredProvider);
    final categories = ref.watch(categoriesProvider);

    Future<void> refresh() async {
      ref.invalidate(featuredProvider);
      ref.invalidate(categoriesProvider);
      await ref.read(catalogRepositoryProvider).featured(force: true).drain<void>().catchError((_) {});
    }

    return Scaffold(
      appBar: AppBar(title: Text(l10n.navExplore), actions: const [AccountMenu()]),
      body: RefreshIndicator(
        onRefresh: refresh,
        child: CustomScrollView(
          slivers: [
            SliverToBoxAdapter(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
                child: SearchBar(
                  hintText: l10n.catalogSearchHint,
                  leading: const Icon(Icons.search),
                  textInputAction: TextInputAction.search,
                  onSubmitted: (q) => context.push(Routes.search, extra: CourseQuery(q: q)),
                ),
              ),
            ),
            if (categories case AsyncData(:final value) when value.data.isNotEmpty)
              SliverToBoxAdapter(
                child: SizedBox(
                  height: 48,
                  child: ListView(
                    scrollDirection: Axis.horizontal,
                    padding: const EdgeInsets.symmetric(horizontal: 12),
                    children: [
                      for (final category in value.data)
                        Padding(
                          padding: const EdgeInsets.symmetric(horizontal: 4),
                          child: ActionChip(
                            label: Text(category.name),
                            onPressed: () => context.push(Routes.search, extra: CourseQuery(category: category.slug)),
                          ),
                        ),
                    ],
                  ),
                ),
              ),
            SliverToBoxAdapter(
              child: CachedView(
                value: featured,
                onRetry: () => ref.invalidate(featuredProvider),
                builder: (context, cached) => Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    OfflineNotice(cached: cached),
                    _Shelf(title: l10n.catalogFeatured, courses: cached.data.featured),
                    _Shelf(
                      title: l10n.catalogPopular,
                      courses: cached.data.popular,
                      query: const CourseQuery(sort: 'popular'),
                    ),
                    _Shelf(
                      title: l10n.catalogNew,
                      courses: cached.data.new2,
                      query: const CourseQuery(sort: 'newest'),
                    ),
                    _Shelf(
                      title: l10n.catalogFree,
                      courses: cached.data.free,
                      query: const CourseQuery(price: 'free'),
                    ),
                    const SizedBox(height: 24),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Shelf extends StatelessWidget {
  const _Shelf({required this.title, required this.courses, this.query});

  final String title;
  final List<CourseCardResource> courses;
  final CourseQuery? query;

  @override
  Widget build(BuildContext context) {
    if (courses.isEmpty) return const SizedBox.shrink();
    final l10n = AppLocalizations.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 16, 8, 8),
          child: Row(
            children: [
              Expanded(child: Text(title, style: Theme.of(context).textTheme.titleLarge)),
              if (query != null)
                TextButton(
                  onPressed: () => context.push(Routes.search, extra: query),
                  child: Text(l10n.catalogSeeAll),
                ),
            ],
          ),
        ),
        SizedBox(
          height: 250,
          child: ListView.separated(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: 16),
            itemCount: courses.length,
            separatorBuilder: (_, _) => const SizedBox(width: 12),
            itemBuilder: (context, i) => courseTile(context, courses[i], compact: true),
          ),
        ),
      ],
    );
  }
}

/// One card for any course list.
Widget courseTile(BuildContext context, CourseCardResource course, {bool compact = false}) => CourseTile(
  title: course.title ?? '',
  professor: course.professor.displayName,
  coverUrl: course.coverUrl,
  isFree: course.isFree,
  price: course.price == null ? null : (amountMinor: course.price!.amountMinor, currency: course.price!.currency),
  rating: course.rating,
  ratingCount: course.ratingCount,
  compact: compact,
  onTap: () => context.push(Routes.course(course.slug)),
);
