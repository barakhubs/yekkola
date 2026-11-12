import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../l10n/generated/app_localizations.dart';
import '../../auth/presentation/api_error_text.dart';
import '../data/catalog_repository.dart';
import 'catalog_providers.dart';
import 'explore_screen.dart';

/// Search and filters (PRD-04 FR-02, FR-03): text, price, language and sort; the API filters and sorts.
/// The first page of each search stays on the phone; more load on scroll.
class SearchScreen extends ConsumerStatefulWidget {
  const SearchScreen({super.key, this.initial = const CourseQuery()});

  final CourseQuery initial;

  @override
  ConsumerState<SearchScreen> createState() => _SearchScreenState();
}

class _SearchScreenState extends ConsumerState<SearchScreen> {
  late CourseQuery _query = widget.initial;
  late final _text = TextEditingController(text: widget.initial.q);

  @override
  void dispose() {
    _text.dispose();
    super.dispose();
  }

  void _set(CourseQuery query) => setState(() => _query = query);

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final results = ref.watch(searchProvider(_query));
    final controller = ref.read(searchProvider(_query).notifier);

    final sorts = {
      'relevance': l10n.sortRelevance,
      'popular': l10n.sortPopular,
      'newest': l10n.sortNewest,
      'rating': l10n.sortRating,
      'price_asc': l10n.sortPriceAsc,
      'price_desc': l10n.sortPriceDesc,
    };

    return Scaffold(
      appBar: AppBar(
        title: TextField(
          controller: _text,
          autofocus: widget.initial.isEmpty,
          textInputAction: TextInputAction.search,
          decoration: InputDecoration(hintText: l10n.catalogSearchHint, border: InputBorder.none),
          onSubmitted: (q) => _set(_query.copyWith(q: q)),
        ),
        actions: [
          PopupMenuButton<String>(
            tooltip: l10n.sortLabel,
            icon: const Icon(Icons.sort),
            initialValue: _query.sort ?? 'relevance',
            onSelected: (sort) => _set(_query.copyWith(sort: sort)),
            itemBuilder: (_) => [
              for (final entry in sorts.entries) PopupMenuItem(value: entry.key, child: Text(entry.value)),
            ],
          ),
        ],
      ),
      body: NotificationListener<ScrollNotification>(
        onNotification: (n) {
          if (n.metrics.extentAfter < 400) controller.loadMore();
          return false;
        },
        child: CustomScrollView(
          slivers: [
            SliverToBoxAdapter(
              child: SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                child: Row(
                  children: [
                    _Filter(
                      label: l10n.filterFree,
                      selected: _query.price == 'free',
                      onTap: (on) => _set(on ? _query.copyWith(price: 'free') : _query.copyWith(clearPrice: true)),
                    ),
                    _Filter(
                      label: l10n.filterPaid,
                      selected: _query.price == 'paid',
                      onTap: (on) => _set(on ? _query.copyWith(price: 'paid') : _query.copyWith(clearPrice: true)),
                    ),
                    _Filter(
                      label: l10n.courseLanguageFr,
                      selected: _query.language == 'fr',
                      onTap: (on) => _set(on ? _query.copyWith(language: 'fr') : _query.copyWith(clearLanguage: true)),
                    ),
                    _Filter(
                      label: l10n.courseLanguageEn,
                      selected: _query.language == 'en',
                      onTap: (on) => _set(on ? _query.copyWith(language: 'en') : _query.copyWith(clearLanguage: true)),
                    ),
                    if (_query.category != null)
                      _Filter(
                        label: l10n.filterCategory,
                        selected: true,
                        onTap: (_) => _set(_query.copyWith(clearCategory: true)),
                      ),
                  ],
                ),
              ),
            ),
            if (results.fetchedAt != null && results.error != null && results.courses.isNotEmpty)
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 16),
                  child: Text(apiErrorText(l10n, results.error!)),
                ),
              ),
            if (results.loading)
              const SliverFillRemaining(child: Center(child: CircularProgressIndicator()))
            else if (results.courses.isEmpty)
              SliverFillRemaining(
                hasScrollBody: false,
                child: Center(
                  child: Padding(
                    padding: const EdgeInsets.all(24),
                    child: Text(
                      results.error?.isNetwork ?? false ? l10n.offlineNothing : l10n.catalogNoResults,
                      textAlign: TextAlign.center,
                    ),
                  ),
                ),
              )
            else ...[
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(16, 4, 16, 4),
                  child: Text(l10n.catalogResults(results.total)),
                ),
              ),
              SliverList.builder(
                itemCount: results.courses.length,
                itemBuilder: (context, i) => courseTile(context, results.courses[i]),
              ),
              if (results.loadingMore)
                const SliverToBoxAdapter(
                  child: Padding(
                    padding: EdgeInsets.all(16),
                    child: Center(child: CircularProgressIndicator()),
                  ),
                ),
              const SliverToBoxAdapter(child: SizedBox(height: 24)),
            ],
          ],
        ),
      ),
    );
  }
}

class _Filter extends StatelessWidget {
  const _Filter({required this.label, required this.selected, required this.onTap});

  final String label;
  final bool selected;
  final void Function(bool selected) onTap;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(horizontal: 4),
    child: FilterChip(label: Text(label), selected: selected, onSelected: onTap),
  );
}
