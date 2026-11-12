import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/i18n/formatters.dart';
import '../../../core/router/app_router.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../auth/presentation/api_error_text.dart';
import 'downloads_controller.dart';

/// Space used on the phone: walked again only when the set of downloads or a finished one changes, not on
/// every progress tick.
final _storageUsedProvider = FutureProvider.autoDispose<int>((ref) async {
  final signature = ref.watch(
    downloadsControllerProvider.select(
      (s) => (s.entries.map((e) => '${e.key}:${e.value.phase == DownloadPhase.done}').toList()..sort()).join(','),
    ),
  );
  final store = ref.read(downloadStoreProvider);
  var total = 0;
  for (final part in signature.split(',').where((p) => p.isNotEmpty)) {
    total += await store.sizeOnDisk(part.split(':').first);
  }
  return total;
});

/// The downloads manager (PRD-07 FR-09): every download of this account by course, with its quality, size,
/// expiry and status; pause, resume, play, delete; storage used; Wi-Fi only. Works fully offline.
class DownloadsScreen extends ConsumerWidget {
  const DownloadsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context);
    final locale = Localizations.localeOf(context).languageCode;
    final downloads = ref.watch(downloadsControllerProvider);
    final wifiOnly = ref.watch(wifiOnlyProvider);
    final used = ref.watch(_storageUsedProvider).value;

    // Grouped by course id (two courses may share a title), shown by title.
    final byCourse = <String?, List<MapEntry<String, DownloadEntry>>>{};
    for (final entry in downloads.entries) {
      byCourse.putIfAbsent(entry.value.courseId, () => []).add(entry);
    }

    Future<void> confirmDeleteAll() async {
      final yes = await showDialog<bool>(
        context: context,
        builder: (context) => AlertDialog(
          title: Text(l10n.downloadDeleteAllTitle),
          content: Text(l10n.downloadDeleteAllBody),
          actions: [
            TextButton(onPressed: () => Navigator.pop(context, false), child: Text(l10n.commonCancel)),
            FilledButton(onPressed: () => Navigator.pop(context, true), child: Text(l10n.downloadDeleteAll)),
          ],
        ),
      );
      if (yes == true) await ref.read(downloadsControllerProvider.notifier).deleteAll();
    }

    return Scaffold(
      appBar: AppBar(
        title: Text(l10n.downloadsTitle),
        actions: [
          if (downloads.isNotEmpty)
            IconButton(
              tooltip: l10n.downloadDeleteAll,
              icon: const Icon(Icons.delete_sweep_outlined),
              onPressed: confirmDeleteAll,
            ),
        ],
      ),
      body: ListView(
        children: [
          SwitchListTile(
            title: Text(l10n.downloadWifiOnly),
            subtitle: Text(l10n.downloadWifiOnlyHint),
            value: wifiOnly,
            onChanged: (value) => ref.read(wifiOnlyProvider.notifier).set(value),
          ),
          if (used != null)
            ListTile(
              leading: const Icon(Icons.sd_storage_outlined),
              title: Text(l10n.downloadStorageUsed(Formatters.bytes(used, locale))),
            ),
          const Divider(),
          if (downloads.isEmpty) Padding(padding: const EdgeInsets.all(24), child: Text(l10n.downloadsEmpty)),
          for (final MapEntry(key: courseId, value: entries) in byCourse.entries) ...[
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 4, 4),
              child: Row(
                children: [
                  Expanded(
                    child: Text(
                      entries.first.value.courseTitle ?? l10n.downloadOtherCourse,
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                  ),
                  // Delete per course (PRD-07 FR-09).
                  IconButton(
                    tooltip: l10n.downloadDeleteCourse,
                    icon: const Icon(Icons.delete_sweep_outlined),
                    onPressed: () => ref.read(downloadsControllerProvider.notifier).deleteCourse(courseId),
                  ),
                ],
              ),
            ),
            for (final entry in entries) _DownloadTile(lessonUid: entry.key, entry: entry.value),
          ],
          const SizedBox(height: 24),
        ],
      ),
    );
  }
}

class _DownloadTile extends ConsumerWidget {
  const _DownloadTile({required this.lessonUid, required this.entry});

  final String lessonUid;
  final DownloadEntry entry;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context);
    final locale = Localizations.localeOf(context).languageCode;
    final controller = ref.read(downloadsControllerProvider.notifier);
    final download = entry.download;
    final percent = (entry.progress * 100).round();
    final expired = download != null && download.isComplete && !download.isUsableAt(DateTime.now().toUtc());

    final status = switch (entry.phase) {
      DownloadPhase.downloading => l10n.mediaDownloading(percent),
      DownloadPhase.queued => l10n.downloadQueued,
      DownloadPhase.waitingForWifi => l10n.downloadWaitingForWifi,
      DownloadPhase.waitingForNetwork => l10n.downloadWaitingForNetwork,
      DownloadPhase.failed => entry.error == null ? l10n.errorGeneric : apiErrorText(l10n, entry.error!),
      _ when expired => l10n.mediaLicenseExpired,
      _ when download != null && download.isComplete => l10n.mediaDownloaded(
        Formatters.bytes(download.sizeBytes, locale),
        Formatters.dateTime(download.expiresAt, locale),
      ),
      _ => l10n.mediaPaused(percent),
    };

    final busy = entry.phase == DownloadPhase.downloading || entry.phase == DownloadPhase.queued;
    final waiting = entry.phase == DownloadPhase.waitingForWifi || entry.phase == DownloadPhase.waitingForNetwork;

    return ListTile(
      leading: Icon(download?.kind == 'audio' ? Icons.headphones : Icons.movie_outlined),
      title: Text(entry.lessonTitle ?? l10n.downloadUntitled),
      subtitle: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text([if (download != null) download.rendition, status].join(' · ')),
          if (entry.phase == DownloadPhase.downloading)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: LinearProgressIndicator(value: entry.progress, semanticsLabel: l10n.mediaDownloading(percent)),
            ),
        ],
      ),
      trailing: Wrap(
        children: [
          if (busy || waiting)
            IconButton(
              tooltip: l10n.mediaPause,
              icon: const Icon(Icons.pause),
              onPressed: () => controller.pause(lessonUid),
            )
          else if (download != null && download.isComplete)
            IconButton(
              tooltip: l10n.mediaPlay,
              icon: const Icon(Icons.play_arrow),
              onPressed: () => context.push(Routes.offlinePlayer(lessonUid), extra: entry.lessonTitle),
            )
          else
            IconButton(
              tooltip: l10n.mediaDownload,
              icon: const Icon(Icons.download),
              onPressed: () => controller.enqueue(lessonUid, rendition: download?.rendition),
            ),
          IconButton(
            tooltip: l10n.mediaDelete,
            icon: const Icon(Icons.delete_outline),
            onPressed: () => controller.delete(lessonUid),
          ),
        ],
      ),
    );
  }
}
