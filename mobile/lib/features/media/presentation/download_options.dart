import 'package:flutter/material.dart';

import '../../../core/i18n/formatters.dart';
import '../../../l10n/generated/app_localizations.dart';

/// One way to download a lesson (from the learning view's `downloads`): our lowest first, the default.
class DownloadOption {
  const DownloadOption({required this.rendition, required this.sizeBytes, this.height});

  /// Reads the API's loosely typed list; unreadable entries are skipped.
  static List<DownloadOption> listFrom(List<Object?> raw) => [
    for (final item in raw)
      if (item is Map && item['rendition'] is String && item['size_bytes'] is int)
        DownloadOption(
          rendition: item['rendition'] as String,
          sizeBytes: item['size_bytes'] as int,
          height: item['height'] is int ? item['height'] as int : null,
        ),
  ];

  final String rendition;
  final int sizeBytes;

  /// Video height in pixels; null for audio.
  final int? height;
}

/// Asks which quality to download, with each size, before anything is downloaded (PRD-07 FR-08). Our lowest
/// is first and recommended (data costs money). Returns the chosen rendition, or null if dismissed.
Future<String?> chooseDownloadQuality(BuildContext context, List<DownloadOption> options, {required bool wifiOnly}) {
  final l10n = AppLocalizations.of(context);
  final locale = Localizations.localeOf(context).languageCode;

  return showModalBottomSheet<String>(
    context: context,
    showDragHandle: true,
    builder: (context) => SafeArea(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(24, 0, 24, 8),
            child: Text(l10n.downloadChooseQuality, style: Theme.of(context).textTheme.titleLarge),
          ),
          for (final (index, option) in options.indexed)
            ListTile(
              leading: Icon(option.height == null ? Icons.headphones : Icons.high_quality_outlined),
              title: Text(
                option.height == null
                    ? l10n.downloadQualityAudio
                    : index == 0
                    ? l10n.downloadQualityRecommended(option.height!)
                    : l10n.downloadQualityHeight(option.height!),
              ),
              trailing: Text(Formatters.bytes(option.sizeBytes, locale)),
              onTap: () => Navigator.pop(context, option.rendition),
            ),
          Padding(
            padding: const EdgeInsets.fromLTRB(24, 8, 24, 16),
            child: Text(wifiOnly ? l10n.downloadWifiOnlyNote : l10n.downloadDataNote),
          ),
        ],
      ),
    ),
  );
}
