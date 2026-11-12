import 'package:flutter/material.dart';

import '../../../l10n/generated/app_localizations.dart';

/// Translated labels for the API's catalogue values (unknown values from newer APIs fall back gracefully).
String levelLabel(AppLocalizations l10n, String level) => switch (level) {
  'beginner' => l10n.levelBeginner,
  'intermediate' => l10n.levelIntermediate,
  'advanced' => l10n.levelAdvanced,
  _ => l10n.levelAll,
};

String audienceLabel(AppLocalizations l10n, String audience) => switch (audience) {
  'exetat' => l10n.audienceExetat,
  'secondaire' => l10n.audienceSecondaire,
  'universite' => l10n.audienceUniversite,
  'professionnel' => l10n.audienceProfessionnel,
  _ => audience,
};

String lessonTypeLabel(AppLocalizations l10n, String type) => switch (type) {
  'video' => l10n.lessonTypeVideo,
  'audio' => l10n.lessonTypeAudio,
  'document' => l10n.lessonTypeDocument,
  'quiz' => l10n.lessonTypeQuiz,
  _ => type,
};

IconData lessonIcon(String type) => switch (type) {
  'video' => Icons.play_circle_outline,
  'audio' => Icons.headphones,
  'document' => Icons.description_outlined,
  'quiz' => Icons.quiz_outlined,
  _ => Icons.circle_outlined,
};

/// `2 h 15 min` / `45 min` (rounded up to the minute).
String durationLabel(AppLocalizations l10n, int seconds) {
  final minutes = (seconds / 60).ceil();
  return minutes >= 60 ? l10n.durationHoursMinutes(minutes ~/ 60, minutes % 60) : l10n.durationMinutes(minutes);
}
