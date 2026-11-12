import 'dart:async';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:video_player/video_player.dart';

import '../../../core/i18n/formatters.dart';
import '../../../core/platform/secure_screen.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../auth/domain/auth_state.dart';
import '../../auth/presentation/api_error_text.dart';
import '../../auth/presentation/auth_controller.dart';
import '../../../core/sync/sync_providers.dart';
import '../../learning/data/progress_sync.dart';
import '../data/offline_media_server.dart';
import '../domain/offline_download.dart';
import 'downloads_controller.dart';
import 'viewer_overlay.dart';

/// Plays a downloaded lesson with no network (PRD-07 FR-11, FR-13, FR-16): checks the license expiry,
/// unwraps the key into memory, serves the encrypted segments to the standard player over loopback, draws
/// the viewer overlay and blocks screen capture on Android.
class OfflinePlayerScreen extends ConsumerStatefulWidget {
  const OfflinePlayerScreen({super.key, required this.lessonUid, this.title});

  final String lessonUid;
  final String? title;

  @override
  ConsumerState<OfflinePlayerScreen> createState() => _OfflinePlayerScreenState();
}

enum _Problem { missing, expired, broken }

class _OfflinePlayerScreenState extends ConsumerState<OfflinePlayerScreen> {
  OfflineMediaServer? _server;
  VideoPlayerController? _player;
  OfflineDownload? _download;
  _Problem? _problem;
  String? _renewError;
  bool _renewing = false;

  @override
  void initState() {
    super.initState();
    final scheduler = ref.read(syncSchedulerProvider);
    _nudge = scheduler.nudge;
    unawaited(SecureScreen.enable());
    unawaited(_open());
  }

  Future<void> _open() async {
    final store = ref.read(downloadStoreProvider);
    final download = await store.read(widget.lessonUid);
    if (!mounted) return;
    final owner = ref.read(authControllerProvider);
    // Another account's download is not ours to play, even on the same phone.
    final mine = download != null && owner is SignedIn && download.userId == owner.user.id;
    if (!mine || !download.isComplete) return setState(() => _problem = _Problem.missing);

    final now = DateTime.now().toUtc();
    if (!download.isUsableAt(now)) {
      return setState(() {
        _download = download;
        _problem = _Problem.expired;
      });
    }

    Uint8List? key;
    OfflineMediaServer? server;
    VideoPlayerController? player;
    try {
      key = await ref.read(offlineKeyVaultProvider).unwrap(download.wrappedKey, boundTo: download.keyBinding);
      server = await OfflineMediaServer.start(store: store, lessonUid: download.lessonUid, key: key);
      player = VideoPlayerController.networkUrl(server.playlistUri, formatHint: VideoFormat.hls);
      await player.initialize();
      await store.save(download.openedAt(now));
      if (!mounted) throw StateError('closed');

      // Resume where this account stopped, unless that was the very end.
      final progress = ref.read(progressRepositoryProvider);
      final saved = await progress.positionOf(owner.user.id, download.lessonUid);
      final resumeAt = Duration(seconds: saved?.positionSeconds ?? 0);
      if (resumeAt > const Duration(seconds: 5) && resumeAt < player.value.duration - const Duration(seconds: 5)) {
        await player.seekTo(resumeAt);
      }
      if (!mounted) throw StateError('closed');
      _saveProgress = () => _record(progress, owner.user.id, download.lessonUid, player!);
      _progressTimer = Timer.periodic(const Duration(seconds: 15), (_) {
        if (player!.value.isPlaying) _saveProgress?.call();
      });
      player.addListener(_onPlayerChange);
      if (!mounted) throw StateError('closed');
      setState(() {
        _download = download;
        _server = server;
        _player = player;
      });
      _expiryCheck = Timer.periodic(const Duration(minutes: 1), (_) => _checkExpiry());
      await player.play();
    } catch (_) {
      // Nothing may outlive a failed start: the server holds the plain key.
      if (_player != player) {
        _progressTimer?.cancel();
        player?.removeListener(_onPlayerChange);
        _saveProgress = null;
      }
      if (_player != player) await player?.dispose();
      if (_server != server) {
        await server?.close();
        if (server == null) key?.fillRange(0, key.length, 0);
      }
      if (mounted && _player == null) setState(() => _problem = _Problem.broken);
    }
  }

  Timer? _expiryCheck;
  Timer? _progressTimer;
  void Function()? _saveProgress;
  bool _wasPlaying = false;

  /// Progress is saved every 15 s while playing and on pause and close (PRD-06 FR-05), locally first and
  /// synced when online.
  void _onPlayerChange() {
    final playing = _player?.value.isPlaying ?? false;
    if (_wasPlaying && !playing) _saveProgress?.call();
    _wasPlaying = playing;
  }

  void _record(ProgressRepository progress, String userId, String lessonUid, VideoPlayerController player) {
    unawaited(
      progress.record(userId: userId, lessonUid: lessonUid, positionSeconds: player.value.position.inSeconds).then((_) {
        _nudge?.call();
      }),
    );
  }

  void Function()? _nudge;

  /// A lesson opened just before its expiry stops when the license ends (PRD-07 FR-11).
  Future<void> _checkExpiry() async {
    final download = _download;
    if (download == null || download.isUsableAt(DateTime.now().toUtc())) return;
    _expiryCheck?.cancel();
    final player = _player;
    final server = _server;
    _saveProgress?.call();
    _progressTimer?.cancel();
    player?.removeListener(_onPlayerChange);
    _saveProgress = null;
    setState(() {
      _player = null;
      _server = null;
      _problem = _Problem.expired;
    });
    await player?.dispose();
    await server?.close();
  }

  Future<void> _renew() async {
    setState(() {
      _renewing = true;
      _renewError = null;
    });
    final error = await ref.read(downloadsControllerProvider.notifier).renew(widget.lessonUid);
    if (!mounted) return;
    if (error != null) {
      setState(() {
        _renewing = false;
        _renewError = apiErrorText(AppLocalizations.of(context), error);
      });
      return;
    }
    setState(() {
      _renewing = false;
      _problem = null;
    });
    _expiryCheck?.cancel();
    await _open();
  }

  @override
  void dispose() {
    _expiryCheck?.cancel();
    _progressTimer?.cancel();
    _player?.removeListener(_onPlayerChange);
    _saveProgress?.call();
    unawaited(SecureScreen.disable());
    unawaited(_player?.dispose());
    unawaited(_server?.close());
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final player = _player;

    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(
        backgroundColor: Colors.black,
        foregroundColor: Colors.white,
        title: Text(widget.title ?? '', maxLines: 1, overflow: TextOverflow.ellipsis),
      ),
      body: SafeArea(
        child: switch (_problem) {
          _Problem.missing => _Message(l10n.mediaNotDownloaded),
          _Problem.broken => _Message(l10n.mediaPlaybackFailed),
          _Problem.expired => _Message(
            l10n.mediaLicenseExpired,
            detail: _renewError,
            action: FilledButton(onPressed: _renewing ? null : _renew, child: Text(l10n.mediaRenew)),
          ),
          null when player == null => const Center(child: CircularProgressIndicator()),
          null => _Player(
            player: player!,
            watermark: _download!.watermark,
            isAudio: _download!.kind == 'audio',
            expiresOn: Formatters.dateTime(_download!.expiresAt, Localizations.localeOf(context).languageCode),
          ),
        },
      ),
    );
  }
}

class _Player extends StatelessWidget {
  const _Player({required this.player, required this.watermark, required this.isAudio, required this.expiresOn});

  final VideoPlayerController player;
  final String watermark;
  final bool isAudio;
  final String expiresOn;

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
                  ViewerOverlay(text: watermark),
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
        Padding(
          padding: const EdgeInsets.only(bottom: 12),
          child: Text(l10n.mediaAvailableUntil(expiresOn), style: const TextStyle(color: Colors.white70)),
        ),
      ],
    );
  }
}

class _Message extends StatelessWidget {
  const _Message(this.text, {this.detail, this.action});

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
