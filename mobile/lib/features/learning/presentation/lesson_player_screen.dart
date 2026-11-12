import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:video_player/video_player.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/api/generated/yekkola_api.dart';
import '../../../core/platform/secure_screen.dart';
import '../../../core/providers.dart';
import '../../../core/sync/sync_providers.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../auth/domain/auth_state.dart';
import '../../auth/presentation/api_error_text.dart';
import '../../auth/presentation/auth_controller.dart';
import '../../media/data/hls_stream_proxy.dart';
import '../../media/presentation/lesson_video_view.dart';

/// Streams a video or audio lesson (PRD-06 FR-03, PRD-07 FR-02, FR-04, FR-13): asks the API for playback
/// (enrolment, account and device checks happen there), plays the encrypted HLS through [HlsStreamProxy] (our
/// credentials never reach the storage host), draws the viewer overlay, blocks screen capture on Android,
/// resumes where this account stopped and saves progress as it plays.
class LessonPlayerScreen extends ConsumerStatefulWidget {
  const LessonPlayerScreen({super.key, required this.lessonUid, this.title});

  final String lessonUid;
  final String? title;

  @override
  ConsumerState<LessonPlayerScreen> createState() => _LessonPlayerScreenState();
}

class _LessonPlayerScreenState extends ConsumerState<LessonPlayerScreen> {
  HlsStreamProxy? _proxy;
  VideoPlayerController? _player;
  ProgressTracker? _tracker;
  PlaybackResource? _playback;
  ApiException? _error;
  bool _failed = false;

  @override
  void initState() {
    super.initState();
    unawaited(SecureScreen.enable());
    unawaited(_open());
  }

  Future<void> _open() async {
    final auth = ref.read(authControllerProvider);
    if (auth is! SignedIn) return;
    HlsStreamProxy? proxy;
    VideoPlayerController? player;
    try {
      final playback = (await ApiException.guard(
        () => ref.read(yekkolaApiProvider).playback.lessonsPlayback(lesson: widget.lessonUid),
      )).data;
      proxy = await HlsStreamProxy.start(api: ref.read(dioProvider), masterUrl: playback.playlistUrl);
      player = VideoPlayerController.networkUrl(proxy.playlistUri, formatHint: VideoFormat.hls);
      await player.initialize();
      if (!mounted) throw StateError('closed');

      final progress = ref.read(progressRepositoryProvider);
      await ProgressTracker.resume(progress, auth.user.id, widget.lessonUid, player);
      if (!mounted) throw StateError('closed');
      final tracker = ProgressTracker(
        progress: progress,
        userId: auth.user.id,
        lessonUid: widget.lessonUid,
        player: player,
        onSaved: ref.read(syncSchedulerProvider).nudge,
      )..start();
      setState(() {
        _playback = playback;
        _proxy = proxy;
        _player = player;
        _tracker = tracker;
      });
      await player.play();
    } on ApiException catch (e) {
      await _discard(proxy, player);
      if (mounted) setState(() => _error = e);
    } catch (_) {
      final refused = proxy?.lastError;
      await _discard(proxy, player);
      if (mounted) setState(() => refused == null ? _failed = true : _error = refused);
    }
  }

  /// Nothing may outlive a failed start: the proxy may hold the key.
  Future<void> _discard(HlsStreamProxy? proxy, VideoPlayerController? player) async {
    if (_player != player) await player?.dispose();
    if (_proxy != proxy) await proxy?.close();
  }

  @override
  void dispose() {
    _tracker?.stop();
    unawaited(SecureScreen.disable());
    unawaited(_player?.dispose());
    unawaited(_proxy?.close());
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final player = _player;
    final playback = _playback;

    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(
        backgroundColor: Colors.black,
        foregroundColor: Colors.white,
        title: Text(widget.title ?? '', maxLines: 1, overflow: TextOverflow.ellipsis),
      ),
      body: SafeArea(
        child: _error != null
            ? PlayerMessage(
                _error!.isNetwork ? l10n.learnStreamOffline : apiErrorText(l10n, _error!),
                action: OutlinedButton(
                  onPressed: () {
                    setState(() => _error = null);
                    unawaited(_open());
                  },
                  child: Text(l10n.commonRetry),
                ),
              )
            : _failed
            ? PlayerMessage(l10n.mediaPlaybackFailed)
            : player == null || playback == null
            ? const Center(child: CircularProgressIndicator())
            : LessonVideoView(player: player, watermark: playback.watermark?.text, isAudio: playback.kind == 'audio'),
      ),
    );
  }
}
