import 'dart:async';

import 'package:flutter/material.dart';
import 'package:video_player/video_player.dart';

import '../../../l10n/generated/app_localizations.dart';
import '../../learning/data/progress_sync.dart';
import 'viewer_overlay.dart';

/// The player both online and offline lessons use: video (or an audio card), the per-viewer overlay
/// (PRD-07 FR-04), a seek bar and play/pause.
class LessonVideoView extends StatelessWidget {
  const LessonVideoView({super.key, required this.player, required this.watermark, required this.isAudio, this.footer});

  final VideoPlayerController player;
  final String? watermark;
  final bool isAudio;
  final String? footer;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final ratio = player.value.aspectRatio > 0 ? player.value.aspectRatio : 16 / 9;

    return Column(
      children: [
        Expanded(
          child: Center(
            child: AspectRatio(
              aspectRatio: isAudio ? 16 / 9 : ratio,
              child: Stack(
                fit: StackFit.expand,
                children: [
                  if (isAudio)
                    const ColoredBox(
                      color: Color(0xFF130F68),
                      child: Icon(Icons.headphones, color: Colors.white54, size: 72),
                    )
                  else
                    VideoPlayer(player),
                  if (watermark != null) ViewerOverlay(text: watermark!),
                ],
              ),
            ),
          ),
        ),
        VideoProgressIndicator(player, allowScrubbing: true, padding: const EdgeInsets.symmetric(horizontal: 16)),
        ValueListenableBuilder<VideoPlayerValue>(
          valueListenable: player,
          builder: (context, value, _) => IconButton(
            iconSize: 48,
            color: Colors.white,
            tooltip: value.isPlaying ? l10n.mediaPause : l10n.mediaPlay,
            icon: Icon(value.isPlaying ? Icons.pause_circle : Icons.play_circle),
            onPressed: () => value.isPlaying ? player.pause() : player.play(),
          ),
        ),
        if (footer != null)
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: Text(footer!, style: const TextStyle(color: Colors.white70)),
          ),
      ],
    );
  }
}

/// A message on the black player background (not downloaded, expired, failed…).
class PlayerMessage extends StatelessWidget {
  const PlayerMessage(this.text, {super.key, this.detail, this.action});

  final String text;
  final String? detail;
  final Widget? action;

  @override
  Widget build(BuildContext context) => Center(
    child: Padding(
      padding: const EdgeInsets.all(24),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            text,
            textAlign: TextAlign.center,
            style: const TextStyle(color: Colors.white, fontSize: 16),
          ),
          if (detail != null) ...[
            const SizedBox(height: 8),
            Text(
              detail!,
              textAlign: TextAlign.center,
              style: const TextStyle(color: Colors.white70),
            ),
          ],
          if (action != null) ...[const SizedBox(height: 16), action!],
        ],
      ),
    ),
  );
}

/// Saves a lesson's position while it plays (PRD-06 FR-05): every 15 s while playing, on pause and on [stop];
/// locally first, synced when online. [resume] seeks to where this account stopped (not the very end).
class ProgressTracker {
  ProgressTracker({
    required this.progress,
    required this.userId,
    required this.lessonUid,
    required this.player,
    this.onSaved,
  });

  final ProgressRepository progress;
  final String userId;
  final String lessonUid;
  final VideoPlayerController player;

  /// Called after each save (the sync scheduler's nudge).
  final void Function()? onSaved;

  Timer? _timer;
  bool _wasPlaying = false;
  bool _stopped = false;

  static Future<void> resume(
    ProgressRepository progress,
    String userId,
    String lessonUid,
    VideoPlayerController player,
  ) async {
    final saved = await progress.positionOf(userId, lessonUid);
    final at = Duration(seconds: saved?.positionSeconds ?? 0);
    if (at > const Duration(seconds: 5) && at < player.value.duration - const Duration(seconds: 5)) {
      await player.seekTo(at);
    }
  }

  void start() {
    _timer = Timer.periodic(const Duration(seconds: 15), (_) {
      if (player.value.isPlaying) save();
    });
    player.addListener(_onChange);
  }

  void _onChange() {
    final playing = player.value.isPlaying;
    if (_wasPlaying && !playing) save();
    _wasPlaying = playing;
  }

  void save() {
    if (_stopped) return;
    final seconds = player.value.position.inSeconds;
    unawaited(
      progress.record(userId: userId, lessonUid: lessonUid, positionSeconds: seconds).then((_) => onSaved?.call()),
    );
  }

  /// Saves one last time and stops listening (before the player is disposed).
  void stop() {
    if (_stopped) return;
    save();
    _stopped = true;
    _timer?.cancel();
    player.removeListener(_onChange);
  }
}
