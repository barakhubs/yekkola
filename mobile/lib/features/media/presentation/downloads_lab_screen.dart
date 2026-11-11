import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/i18n/formatters.dart';
import '../../../core/providers.dart';
import '../../../core/router/app_router.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../auth/presentation/api_error_text.dart';
import 'downloads_controller.dart';

class LabLesson {
  const LabLesson({required this.uid, required this.title, required this.kind});

  final String uid;
  final String title;
  final String kind;
}

class LabCourse {
  const LabCourse({required this.title, required this.lessons});

  final String title;
  final List<LabLesson> lessons;
}

/// My courses' video and audio lessons, for the offline prototype (staging builds only).
final labCoursesProvider = FutureProvider.autoDispose<List<LabCourse>>((ref) async {
  final api = ref.watch(yekkolaApiProvider);
  final mine = await ApiException.guard(() => api.learning.meEnrollmentsIndex(perPage: 20));
  final courses = <LabCourse>[];
  for (final enrollment in mine.data) {
    final view = await ApiException.guard(() => api.learning.meCoursesShow(course: enrollment.course.id));
    final lessons = [
      for (final section in view.data.sections)
        for (final lesson in section.lessons)
          if (lesson.type2 == 'video' || lesson.type2 == 'audio')
            LabLesson(uid: lesson.uid, title: lesson.title, kind: lesson.type2),
    ];
    if (lessons.isNotEmpty) courses.add(LabCourse(title: view.data.course.title ?? '', lessons: lessons));
  }
  return courses;
}, retry: (_, _) => null);

/// Offline prototype (PRD-07 FR-16): download a lesson's encrypted segments, then play it in airplane mode.
/// Staging builds only, until the downloads manager replaces it.
class DownloadsLabScreen extends ConsumerWidget {
  const DownloadsLabScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context);
    final courses = ref.watch(labCoursesProvider);

    return Scaffold(
      appBar: AppBar(title: Text(l10n.mediaLabTitle)),
      body: RefreshIndicator(
        onRefresh: () => ref.refresh(labCoursesProvider.future),
        child: ListView(
          padding: const EdgeInsets.symmetric(vertical: 8),
          children: [
            Padding(padding: const EdgeInsets.all(16), child: Text(l10n.mediaLabIntro)),
            ...courses.when(
              loading: () => [
                const Padding(
                  padding: EdgeInsets.all(32),
                  child: Center(child: CircularProgressIndicator()),
                ),
              ],
              // Offline: what is already on the phone can still be played.
              error: (error, _) => [
                Padding(
                  padding: const EdgeInsets.all(16),
                  child: Text(error is ApiException ? apiErrorText(l10n, error) : l10n.errorGeneric),
                ),
                const _DownloadedOnly(),
              ],
              data: (list) => list.isEmpty
                  ? [Padding(padding: const EdgeInsets.all(16), child: Text(l10n.mediaLabEmpty))]
                  : [
                      for (final course in list) ...[
                        Padding(
                          padding: const EdgeInsets.fromLTRB(16, 16, 16, 4),
                          child: Text(course.title, style: Theme.of(context).textTheme.titleMedium),
                        ),
                        for (final lesson in course.lessons) _LessonTile(lesson: lesson),
                      ],
                    ],
            ),
          ],
        ),
      ),
    );
  }
}

class _DownloadedOnly extends ConsumerWidget {
  const _DownloadedOnly();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final downloads = ref.watch(downloadsControllerProvider);
    return Column(
      children: [
        for (final entry in downloads.entries)
          if (entry.value.download != null)
            _LessonTile(
              lesson: LabLesson(uid: entry.key, title: entry.key, kind: entry.value.download!.kind),
            ),
      ],
    );
  }
}

class _LessonTile extends ConsumerWidget {
  const _LessonTile({required this.lesson});

  final LabLesson lesson;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context);
    final locale = Localizations.localeOf(context).languageCode;
    final entry = ref.watch(downloadsControllerProvider.select((s) => s[lesson.uid])) ?? const DownloadEntry();
    final controller = ref.read(downloadsControllerProvider.notifier);
    final download = entry.download;

    final status = switch (entry.phase) {
      DownloadPhase.downloading => l10n.mediaDownloading((entry.progress * 100).round()),
      DownloadPhase.done when download != null => l10n.mediaDownloaded(
        Formatters.bytes(download.sizeBytes, locale),
        Formatters.dateTime(download.expiresAt, locale),
      ),
      DownloadPhase.failed => entry.error == null ? l10n.errorGeneric : apiErrorText(l10n, entry.error!),
      _ when download != null && !download.isComplete => l10n.mediaPaused((entry.progress * 100).round()),
      _ => lesson.kind == 'audio' ? l10n.mediaAudioLesson : l10n.mediaVideoLesson,
    };

    return ListTile(
      leading: Icon(lesson.kind == 'audio' ? Icons.headphones : Icons.movie_outlined),
      title: Text(lesson.title),
      subtitle: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(status),
          if (entry.phase == DownloadPhase.downloading)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: LinearProgressIndicator(value: entry.progress),
            ),
        ],
      ),
      trailing: Wrap(
        children: [
          if (entry.phase == DownloadPhase.downloading)
            IconButton(
              tooltip: l10n.mediaPause,
              icon: const Icon(Icons.pause),
              onPressed: () => controller.pause(lesson.uid),
            )
          else if (download?.isComplete ?? false)
            IconButton(
              tooltip: l10n.mediaPlay,
              icon: const Icon(Icons.play_arrow),
              onPressed: () => context.push(Routes.offlinePlayer(lesson.uid), extra: lesson.title),
            )
          else
            IconButton(
              tooltip: l10n.mediaDownload,
              icon: const Icon(Icons.download),
              onPressed: () => controller.start(lesson.uid),
            ),
          if (download != null)
            IconButton(
              tooltip: l10n.mediaDelete,
              icon: const Icon(Icons.delete_outline),
              onPressed: () => controller.delete(lesson.uid),
            ),
        ],
      ),
    );
  }
}
