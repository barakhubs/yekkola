import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/api/generated/yekkola_api.dart';
import '../../../core/i18n/formatters.dart';
import '../../../core/router/app_router.dart';
import '../../../l10n/generated/app_localizations.dart';
import 'catalog_labels.dart';
import 'catalog_providers.dart';
import 'widgets/catalog_widgets.dart';

/// A course page (PRD-04 FR-04): what it teaches, who teaches it, the curriculum with download sizes (data
/// cost matters — PRD-07 FR-08) and the price. Readable offline once opened. Enrolling comes with checkout.
class CourseScreen extends ConsumerWidget {
  const CourseScreen({super.key, required this.slug});

  final String slug;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final value = ref.watch(courseProvider(slug));

    return Scaffold(
      appBar: AppBar(),
      body: CachedView(
        value: value,
        onRetry: () => ref.invalidate(courseProvider(slug)),
        builder: (context, cached) => RefreshIndicator(
          onRefresh: () =>
              ref.read(catalogRepositoryProvider).course(slug, force: true).drain<void>().catchError((_) {}),
          child: _CourseBody(
            course: cached.data,
            notice: OfflineNotice(cached: cached),
          ),
        ),
      ),
    );
  }
}

class _CourseBody extends StatelessWidget {
  const _CourseBody({required this.course, required this.notice});

  final CourseDetailResource course;
  final Widget notice;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final theme = Theme.of(context);
    final locale = Localizations.localeOf(context).languageCode;
    final muted = theme.textTheme.bodyMedium?.copyWith(color: theme.colorScheme.onSurfaceVariant);
    final width = MediaQuery.sizeOf(context).width;

    return ListView(
      children: [
        notice,
        NetImage(course.coverUrl, width: width, height: width * 9 / 16, radius: 0),
        Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(course.title ?? '', style: theme.textTheme.headlineMedium),
              if (course.subtitle != null) ...[const SizedBox(height: 4), Text(course.subtitle!, style: muted)],
              const SizedBox(height: 12),
              PriceTag(
                isFree: course.isFree,
                amountMinor: course.price?.amountMinor,
                currency: course.price?.currency,
                large: true,
              ),
              const SizedBox(height: 12),
              Wrap(
                spacing: 16,
                runSpacing: 8,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  Rating(rating: course.rating, count: course.ratingCount),
                  if (course.enrollmentCount > 0) Text(l10n.courseStudents(course.enrollmentCount), style: muted),
                  Text(levelLabel(l10n, course.level), style: muted),
                  Text(course.language == 'en' ? l10n.courseLanguageEn : l10n.courseLanguageFr, style: muted),
                  for (final audience in course.audience) Text(audienceLabel(l10n, audience), style: muted),
                ],
              ),
              const SizedBox(height: 16),
              _ProfessorRow(professor: course.professor),
            ],
          ),
        ),
        if (course.outcomes.isNotEmpty)
          _Section(
            title: l10n.courseOutcomes,
            children: [
              for (final outcome in course.outcomes)
                ListTile(dense: true, leading: const Icon(Icons.check), title: Text(outcome)),
            ],
          ),
        if ((course.description ?? '').isNotEmpty)
          _Section(
            title: l10n.courseAbout,
            children: [Padding(padding: const EdgeInsets.symmetric(horizontal: 16), child: Text(course.description!))],
          ),
        if (course.requirements.isNotEmpty)
          _Section(
            title: l10n.courseRequirements,
            children: [
              for (final requirement in course.requirements)
                ListTile(dense: true, leading: const Icon(Icons.circle, size: 8), title: Text(requirement)),
            ],
          ),
        _Section(
          title: l10n.courseCurriculum,
          subtitle: [
            l10n.courseLessons(course.lessonCount),
            durationLabel(l10n, course.totalDurationSeconds),
            if (course.downloadBytes > 0) l10n.courseDownloadSize(Formatters.bytes(course.downloadBytes, locale)),
          ].join(' · '),
          children: [
            for (final section in course.sections)
              ExpansionTile(
                title: Text(section.title, style: theme.textTheme.titleSmall),
                subtitle: Text(l10n.courseLessons(section.lessons.length)),
                initiallyExpanded: course.sections.length <= 2,
                children: [
                  for (final lesson in section.lessons)
                    ListTile(
                      dense: true,
                      leading: Icon(lessonIcon(lesson.type2), semanticLabel: lessonTypeLabel(l10n, lesson.type2)),
                      title: Text(lesson.title),
                      subtitle: Text(
                        [
                          if (lesson.durationSeconds != null) durationLabel(l10n, lesson.durationSeconds!),
                          if (lesson.downloadBytes > 0) Formatters.bytes(lesson.downloadBytes, locale),
                        ].join(' · '),
                      ),
                      trailing: lesson.isPreview ? Chip(label: Text(l10n.coursePreview)) : null,
                    ),
                ],
              ),
          ],
        ),
        const SizedBox(height: 32),
      ],
    );
  }
}

class _ProfessorRow extends StatelessWidget {
  const _ProfessorRow({required this.professor});

  final PublicProfessorResource professor;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return InkWell(
      onTap: () => context.push(Routes.professor(professor.slug)),
      borderRadius: BorderRadius.circular(8),
      child: Row(
        children: [
          NetImage(professor.avatarUrl, width: 44, height: 44, radius: 22, icon: Icons.person_outline),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(l10n.courseBy(professor.displayName), style: Theme.of(context).textTheme.titleSmall),
                if (professor.headline != null) Text(professor.headline!, maxLines: 1, overflow: TextOverflow.ellipsis),
              ],
            ),
          ),
          if (professor.isVerified) Icon(Icons.verified, semanticLabel: l10n.professorVerified),
          const Icon(Icons.chevron_right),
        ],
      ),
    );
  }
}

class _Section extends StatelessWidget {
  const _Section({required this.title, required this.children, this.subtitle});

  final String title;
  final String? subtitle;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(top: 8),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 4),
          child: Text(title, style: Theme.of(context).textTheme.titleLarge),
        ),
        if (subtitle != null) Padding(padding: const EdgeInsets.fromLTRB(16, 0, 16, 4), child: Text(subtitle!)),
        ...children,
      ],
    ),
  );
}
