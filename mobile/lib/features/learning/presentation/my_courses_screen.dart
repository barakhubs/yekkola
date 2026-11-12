import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/api/generated/yekkola_api.dart';
import '../../../core/i18n/formatters.dart';
import '../../../core/router/app_router.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../catalog/presentation/widgets/catalog_widgets.dart';
import '../../home/presentation/account_menu.dart';
import 'learning_providers.dart';

enum _Filter { all, inProgress, completed }

/// The "My courses" tab (PRD-06 FR-01): enrolled courses with progress and last activity, most recently active
/// first (the API's order), filterable by in progress / completed; readable offline once loaded.
class MyCoursesScreen extends ConsumerStatefulWidget {
  const MyCoursesScreen({super.key});

  @override
  ConsumerState<MyCoursesScreen> createState() => _MyCoursesScreenState();
}

class _MyCoursesScreenState extends ConsumerState<MyCoursesScreen> {
  _Filter _filter = _Filter.all;

  bool _matches(MyCourseResource mine) => switch (_filter) {
    _Filter.all => true,
    _Filter.inProgress => mine.completedAt == null,
    _Filter.completed => mine.completedAt != null,
  };

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final courses = ref.watch(myCoursesProvider);

    return Scaffold(
      appBar: AppBar(title: Text(l10n.homeTitle), actions: const [AccountMenu()]),
      body: CachedView(
        value: courses,
        onRetry: () => ref.invalidate(myCoursesProvider),
        builder: (context, cached) {
          final shown = cached.data.where(_matches).toList();
          return RefreshIndicator(
            onRefresh: () {
              ref.read(learningRefreshProvider.notifier).pull();
              return ref.read(myCoursesProvider.future).then((_) {}, onError: (_) {});
            },
            child: ListView(
              children: [
                OfflineNotice(cached: cached),
                if (cached.data.isEmpty)
                  Padding(
                    padding: const EdgeInsets.all(24),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        Text(l10n.learnNoCourses, style: Theme.of(context).textTheme.titleMedium),
                        const SizedBox(height: 12),
                        FilledButton(onPressed: () => context.go(Routes.explore), child: Text(l10n.learnExplore)),
                      ],
                    ),
                  )
                else ...[
                  Padding(
                    padding: const EdgeInsets.fromLTRB(12, 8, 12, 0),
                    child: Wrap(
                      spacing: 8,
                      children: [
                        for (final (filter, label) in [
                          (_Filter.all, l10n.learnFilterAll),
                          (_Filter.inProgress, l10n.learnFilterInProgress),
                          (_Filter.completed, l10n.learnFilterCompleted),
                        ])
                          ChoiceChip(
                            label: Text(label),
                            selected: _filter == filter,
                            onSelected: (_) => setState(() => _filter = filter),
                          ),
                      ],
                    ),
                  ),
                  if (shown.isEmpty) Padding(padding: const EdgeInsets.all(24), child: Text(l10n.learnNoneInFilter)),
                  for (final mine in shown) _CourseRow(mine: mine),
                ],
              ],
            ),
          );
        },
      ),
    );
  }
}

class _CourseRow extends StatelessWidget {
  const _CourseRow({required this.mine});

  final MyCourseResource mine;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final theme = Theme.of(context);
    final locale = Localizations.localeOf(context).languageCode;
    final lastActivity = DateTime.tryParse(mine.lastActivityAt ?? '');

    return InkWell(
      onTap: () => context.push(Routes.learn(mine.course.id)),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
        child: Row(
          children: [
            NetImage(mine.course.coverUrl, width: 112, height: 63),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    mine.course.title ?? '',
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.titleSmall,
                  ),
                  const SizedBox(height: 6),
                  LinearProgressIndicator(
                    value: mine.progressPct / 100,
                    semanticsLabel: l10n.learnProgress(mine.progressPct),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    [
                      mine.completedAt != null ? l10n.learnCompleted : l10n.learnProgress(mine.progressPct),
                      if (lastActivity != null) l10n.learnLastActivity(Formatters.date(lastActivity, locale)),
                    ].join(' · '),
                    style: theme.textTheme.bodySmall,
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
