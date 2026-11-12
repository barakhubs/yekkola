import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/api/generated/yekkola_api.dart';
import '../../../core/router/app_router.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../auth/presentation/api_error_text.dart';
import '../../catalog/presentation/catalog_labels.dart';
import '../../catalog/presentation/widgets/catalog_widgets.dart';
import '../../media/data/hls_downloader.dart';
import '../../media/presentation/download_options.dart';
import '../../media/presentation/downloads_controller.dart';
import '../../../core/sync/sync_providers.dart';
import 'learning_providers.dart';
import 'lesson_player_screen.dart';

/// A course as its student sees it (PRD-06 FR-02): progress, "continue", and the curriculum with each
/// lesson's status. Video and audio lessons play online or, once downloaded, offline (PRD-07 FR-16);
/// downloads start here. Readable offline once opened.
class LearnCourseScreen extends ConsumerWidget {
  const LearnCourseScreen({super.key, required this.courseId});

  final String courseId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context);
    final value = ref.watch(learnCourseProvider(courseId));

    return Scaffold(
      appBar: AppBar(title: Text(value.value?.data.course.title ?? '', maxLines: 1, overflow: TextOverflow.ellipsis)),
      body: CachedView(
        value: value,
        onRetry: () => ref.invalidate(learnCourseProvider(courseId)),
        builder: (context, cached) {
          final view = cached.data;
          final lessons = [for (final s in view.sections) ...s.lessons];
          LearningViewResourceSectionsItemLessonsItem? continueWith;
          for (final lesson in lessons) {
            if (lesson.uid == view.continueLessonUid) continueWith = lesson;
          }

          return RefreshIndicator(
            onRefresh: () {
              ref.read(learningRefreshProvider.notifier).pull();
              return ref.read(learnCourseProvider(courseId).future).then((_) {}, onError: (_) {});
            },
            child: ListView(
              children: [
                OfflineNotice(cached: cached),
                Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      LinearProgressIndicator(
                        value: view.progressPct / 100,
                        semanticsLabel: l10n.learnProgress(view.progressPct),
                      ),
                      const SizedBox(height: 6),
                      Text(view.completedAt != null ? l10n.learnCompleted : l10n.learnProgress(view.progressPct)),
                      if (continueWith != null) ...[
                        const SizedBox(height: 12),
                        FilledButton.icon(
                          icon: const Icon(Icons.play_arrow),
                          onPressed: () => openLesson(context, ref, courseId, continueWith!),
                          label: Text(l10n.learnContinue(continueWith.title)),
                        ),
                      ],
                    ],
                  ),
                ),
                for (final section in view.sections) ...[
                  Padding(
                    padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
                    child: Text(section.title, style: Theme.of(context).textTheme.titleMedium),
                  ),
                  for (final lesson in section.lessons) _LessonRow(courseId: courseId, lesson: lesson),
                ],
                const SizedBox(height: 24),
              ],
            ),
          );
        },
      ),
    );
  }
}

bool _playable(String type) => type == 'video' || type == 'audio';

/// Plays a video/audio lesson: the download if this account has a complete one, else streaming. Documents and
/// quizzes come in a later step.
Future<void> openLesson(
  BuildContext context,
  WidgetRef ref,
  String courseId,
  LearningViewResourceSectionsItemLessonsItem lesson,
) async {
  if (!_playable(lesson.type2)) {
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(AppLocalizations.of(context).learnSoon)));
    return;
  }
  final download = ref.read(downloadsControllerProvider)[lesson.uid]?.download;
  // A complete download still within its license plays offline; an expired one streams instead (online).
  if (download != null && download.isComplete && download.isUsableAt(DateTime.now().toUtc())) {
    await context.push(Routes.offlinePlayer(lesson.uid), extra: lesson.title);
  } else {
    await context.push(
      Routes.lesson(courseId, lesson.uid),
      extra: LessonArgs(title: lesson.title, positionSeconds: lesson.positionSeconds),
    );
  }
  // Back from the lesson: send the progress, then show the updated statuses and "continue".
  if (!context.mounted) return;
  final engine = ref.read(syncEngineProvider);
  final refresh = ref.read(learningRefreshProvider.notifier);
  await engine.flush();
  refresh.pull();
}

class _LessonRow extends ConsumerWidget {
  const _LessonRow({required this.courseId, required this.lesson});

  final String courseId;
  final LearningViewResourceSectionsItemLessonsItem lesson;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context);
    final entry = ref.watch(downloadsControllerProvider.select((s) => s[lesson.uid]));
    final download = entry?.download;
    final controller = ref.read(downloadsControllerProvider.notifier);

    final status = switch (lesson.status) {
      'completed' => Icon(
        Icons.check_circle,
        color: Theme.of(context).colorScheme.primary,
        semanticLabel: l10n.learnLessonDone,
      ),
      'in_progress' => Icon(Icons.timelapse, semanticLabel: l10n.learnLessonStarted),
      _ => Icon(lessonIcon(lesson.type2), semanticLabel: lessonTypeLabel(l10n, lesson.type2)),
    };

    final details = [
      lessonTypeLabel(l10n, lesson.type2),
      if (lesson.durationSeconds != null) durationLabel(l10n, lesson.durationSeconds!),
      switch (entry?.phase) {
        DownloadPhase.downloading => l10n.mediaDownloading((entry!.progress * 100).round()),
        DownloadPhase.failed when entry?.error != null => apiErrorText(l10n, entry!.error!),
        _ when download != null && download.isComplete => l10n.learnDownloaded,
        _ => null,
      },
    ].whereType<String>().join(' · ');

    Widget? trailing;
    if (_playable(lesson.type2)) {
      if (entry?.phase == DownloadPhase.downloading) {
        trailing = IconButton(
          tooltip: l10n.mediaPause,
          icon: const Icon(Icons.pause),
          onPressed: () => controller.pause(lesson.uid),
        );
      } else if (download != null && download.isComplete) {
        trailing = IconButton(
          tooltip: l10n.mediaDelete,
          icon: const Icon(Icons.download_done),
          onPressed: () => controller.delete(lesson.uid),
        );
      } else {
        trailing = IconButton(
          tooltip: l10n.mediaDownload,
          icon: const Icon(Icons.download_outlined),
          onPressed: () async {
            // Sizes before anything is downloaded; our lowest is the default (PRD-07 FR-08).
            final options = DownloadOption.listFrom(lesson.downloads);
            final rendition = options.length <= 1
                ? options.firstOrNull?.rendition
                : await chooseDownloadQuality(context, options, wifiOnly: ref.read(wifiOnlyProvider));
            if (options.length > 1 && rendition == null) return;
            final view = ref.read(learnCourseProvider(courseId)).value?.data;
            controller.enqueue(
              lesson.uid,
              rendition: rendition,
              labels: DownloadLabels(courseId: courseId, courseTitle: view?.course.title, lessonTitle: lesson.title),
            );
          },
        );
      }
    }

    return ListTile(
      leading: status,
      title: Text(lesson.title),
      subtitle: Text(details),
      trailing: trailing,
      onTap: () => openLesson(context, ref, courseId, lesson),
    );
  }
}
