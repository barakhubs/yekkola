import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/router/app_router.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../catalog/presentation/widgets/catalog_widgets.dart';
import '../../home/presentation/account_menu.dart';
import 'learning_providers.dart';

/// The "My courses" tab (PRD-06 FR-01): enrolled courses with progress, most recently active first; readable
/// offline once loaded.
class MyCoursesScreen extends ConsumerWidget {
  const MyCoursesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context);
    final courses = ref.watch(myCoursesProvider);

    return Scaffold(
      appBar: AppBar(title: Text(l10n.homeTitle), actions: const [AccountMenu()]),
      body: CachedView(
        value: courses,
        onRetry: () => ref.invalidate(myCoursesProvider),
        builder: (context, cached) => RefreshIndicator(
          onRefresh: () {
            ref.read(learningRefreshProvider.notifier).pull();
            return ref.read(myCoursesProvider.future).then((_) {}, onError: (_) {});
          },
          child: cached.data.isEmpty
              ? ListView(
                  padding: const EdgeInsets.all(24),
                  children: [
                    OfflineNotice(cached: cached),
                    Text(l10n.learnNoCourses, style: Theme.of(context).textTheme.titleMedium),
                    const SizedBox(height: 12),
                    FilledButton(onPressed: () => context.go(Routes.explore), child: Text(l10n.learnExplore)),
                  ],
                )
              : ListView(
                  children: [
                    OfflineNotice(cached: cached),
                    for (final mine in cached.data)
                      InkWell(
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
                                      style: Theme.of(context).textTheme.titleSmall,
                                    ),
                                    const SizedBox(height: 6),
                                    LinearProgressIndicator(
                                      value: mine.progressPct / 100,
                                      semanticsLabel: l10n.learnProgress(mine.progressPct),
                                    ),
                                    const SizedBox(height: 4),
                                    Text(
                                      mine.completedAt != null
                                          ? l10n.learnCompleted
                                          : l10n.learnProgress(mine.progressPct),
                                      style: Theme.of(context).textTheme.bodySmall,
                                    ),
                                  ],
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                  ],
                ),
        ),
      ),
    );
  }
}
