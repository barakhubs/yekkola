import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/generated/yekkola_api.dart';
import '../../../core/i18n/formatters.dart';
import '../../../l10n/generated/app_localizations.dart';
import 'catalog_providers.dart';
import 'explore_screen.dart';
import 'widgets/catalog_widgets.dart';

/// A professor's public page (PRD-02 FR-05, PRD-04 FR-05): who they are, verification, subjects and their
/// courses. Readable offline once opened. The province is shown only if the professor gave one.
class ProfessorScreen extends ConsumerWidget {
  const ProfessorScreen({super.key, required this.slug});

  final String slug;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final value = ref.watch(professorProvider(slug));

    return Scaffold(
      appBar: AppBar(),
      body: CachedView(
        value: value,
        onRetry: () => ref.invalidate(professorProvider(slug)),
        builder: (context, cached) => RefreshIndicator(
          onRefresh: () {
            ref.read(catalogRefreshProvider.notifier).pull();
            return ref.read(professorProvider(slug).future).then((_) {}, onError: (_) {});
          },
          child: _ProfessorBody(
            professor: cached.data,
            notice: OfflineNotice(cached: cached),
          ),
        ),
      ),
    );
  }
}

class _ProfessorBody extends StatelessWidget {
  const _ProfessorBody({required this.professor, required this.notice});

  final PublicProfessorResource professor;
  final Widget notice;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final theme = Theme.of(context);
    final locale = Localizations.localeOf(context).languageCode;
    final muted = theme.textTheme.bodyMedium?.copyWith(color: theme.colorScheme.onSurfaceVariant);
    final province = professor.province.asProvinceResource?.name;
    final since = DateTime.tryParse(professor.memberSince);
    final courses = professor.courses ?? const <CourseCardResource>[];

    return ListView(
      children: [
        notice,
        Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  NetImage(professor.avatarUrl, width: 72, height: 72, radius: 36, icon: Icons.person_outline),
                  const SizedBox(width: 16),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(professor.displayName, style: theme.textTheme.headlineSmall),
                        if (professor.isVerified)
                          Row(
                            children: [
                              Icon(Icons.verified, size: 18, color: theme.colorScheme.primary),
                              const SizedBox(width: 4),
                              Text(l10n.professorVerified, style: muted),
                            ],
                          ),
                      ],
                    ),
                  ),
                ],
              ),
              if (professor.headline != null) ...[
                const SizedBox(height: 12),
                Text(professor.headline!, style: theme.textTheme.titleMedium),
              ],
              const SizedBox(height: 12),
              Wrap(
                spacing: 16,
                runSpacing: 8,
                children: [
                  Rating(rating: professor.rating, count: professor.ratingCount),
                  if (professor.studentCount > 0) Text(l10n.courseStudents(professor.studentCount), style: muted),
                  if (province != null) Text(province, style: muted),
                  if (since != null) Text(l10n.professorMemberSince(Formatters.monthYear(since, locale)), style: muted),
                ],
              ),
              if (professor.subjects.isNotEmpty) ...[
                const SizedBox(height: 12),
                Wrap(
                  spacing: 8,
                  runSpacing: 4,
                  children: [for (final subject in professor.subjects) Chip(label: Text(subject))],
                ),
              ],
              if ((professor.bio ?? '').isNotEmpty) ...[const SizedBox(height: 12), Text(professor.bio!)],
            ],
          ),
        ),
        if (courses.isNotEmpty) ...[
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 4),
            child: Text(l10n.professorCourses, style: theme.textTheme.titleLarge),
          ),
          for (final course in courses) courseTile(context, course),
        ],
        const SizedBox(height: 32),
      ],
    );
  }
}
