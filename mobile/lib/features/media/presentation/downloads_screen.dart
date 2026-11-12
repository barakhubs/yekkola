import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/i18n/formatters.dart';
import '../../../core/router/app_router.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../auth/presentation/api_error_text.dart';
import 'downloads_controller.dart';

final _storageUsedProvider = FutureProvider.autoDispose<int>((ref) async {
  final downloads = ref.watch(downloadsControllerProvider);
  final store = ref.read(downloadStoreProvider);
  var total = 0;
  for (final uid in downloads.keys) {
    total += await store.sizeOnDisk(uid);
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

    final byCourse = <String, List<MapEntry<String, DownloadEntry>>>{};
    for (final entry in downloads.entries) {
      final course = entry.value.download?.courseTitle ?? l10n.downloadOtherCourse;
      byCourse.putIfAbsent(course, () => []).add(entry);
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
          for (final MapEntry(key: course, value: entries) in byCourse.entries) ...[
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
              child: Text(course, style: Theme.of(context).textTheme.titleMedium),
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
      DownloadPhase.failed => entry.error == null ? l10n.errorGeneric : apiErrorText(l10n, entry.error!),
      _ when expired => l10n.mediaLicenseExpired,
      _ when download != null && download.isComplete => l10n.mediaDownloaded(
        Formatters.bytes(download.sizeBytes, locale),
        Formatters.dateTime(download.expiresAt, locale),
      ),
      _ => l10n.mediaPaused(percent),
    };

    final busy = entry.phase == DownloadPhase.downloading || entry.phase == DownloadPhase.queued;
    final waiting = entry.phase == DownloadPhase.waitingForWifi;

    return ListTile(
      leading: Icon(download?.kind == 'audio' ? Icons.headphones : Icons.movie_outlined),
      title: Text(download?.lessonTitle ?? lessonUid),
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
              onPressed: () => context.push(Routes.offlinePlayer(lessonUid), extra: download.lessonTitle),
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
