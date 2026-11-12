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

/// What the course view knows when it opens a lesson: its title and the API's saved position.
class LessonArgs {
  const LessonArgs({this.title, this.positionSeconds});

  final String? title;
  final int? positionSeconds;
}

/// Streams a video or audio lesson (PRD-06 FR-03, PRD-07 FR-02, FR-04, FR-13): asks the API for playback
/// (enrolment, account and device checks happen there), plays the encrypted HLS through [HlsStreamProxy] (our
/// credentials never reach the storage host), draws the viewer overlay, blocks screen capture on Android,
/// resumes at the furthest of this phone's and the API's position, and saves progress as it plays. A failure
/// mid-lesson (expired segment links, access ended) stops cleanly with the reason and a retry, which asks for
/// playback again and resumes where it stopped.
class LessonPlayerScreen extends ConsumerStatefulWidget {
  const LessonPlayerScreen({super.key, required this.lessonUid, this.args = const LessonArgs()});

  final String lessonUid;
  final LessonArgs args;

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
  bool _signedOut = false;

  @override
  void initState() {
    super.initState();
    unawaited(SecureScreen.enable());
    unawaited(_open());
  }

  Future<void> _open() async {
    final auth = ref.read(authControllerProvider);
    if (auth is! SignedIn) {
      setState(() => _signedOut = true);
      return;
    }
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
      await ProgressTracker.resume(
        progress,
        auth.user.id,
        widget.lessonUid,
        player,
        serverSeconds: widget.args.positionSeconds,
      );
      if (!mounted) throw StateError('closed');
      final tracker = ProgressTracker(
        progress: progress,
        userId: auth.user.id,
        lessonUid: widget.lessonUid,
        player: player,
        onSaved: ref.read(syncSchedulerProvider).nudge,
      )..start();
      player.addListener(_watchForFailure);
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

  /// The player stopped on an error after it started: save the position, release everything, say why.
  void _watchForFailure() {
    final player = _player;
    if (player == null || !player.value.hasError) return;
    final refused = _proxy?.lastError;
    unawaited(_stop());
    setState(() => refused == null ? _failed = true : _error = refused);
  }

  Future<void> _stop() async {
    final player = _player;
    final proxy = _proxy;
    _tracker?.stop();
    player?.removeListener(_watchForFailure);
    setState(() {
      _tracker = null;
      _player = null;
      _proxy = null;
      _playback = null;
    });
    await player?.dispose();
    await proxy?.close();
  }

  void _retry() {
    setState(() {
      _error = null;
      _failed = false;
    });
    unawaited(_open());
  }

  /// Nothing may outlive a failed start: the proxy may hold the key.
  Future<void> _discard(HlsStreamProxy? proxy, VideoPlayerController? player) async {
    if (_player != player) await player?.dispose();
    if (_proxy != proxy) await proxy?.close();
  }

  @override
  void dispose() {
    _tracker?.stop();
    _player?.removeListener(_watchForFailure);
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
    final retry = OutlinedButton(onPressed: _retry, child: Text(l10n.commonRetry));

    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(
        backgroundColor: Colors.black,
        foregroundColor: Colors.white,
        title: Text(widget.args.title ?? '', maxLines: 1, overflow: TextOverflow.ellipsis),
      ),
      body: SafeArea(
        child: _signedOut
            ? PlayerMessage(l10n.authSessionExpired)
            : _error != null
            ? PlayerMessage(_error!.isNetwork ? l10n.learnStreamOffline : apiErrorText(l10n, _error!), action: retry)
            : _failed
            ? PlayerMessage(l10n.playerStopped, detail: l10n.mediaPlaybackFailed, action: retry)
            : player == null || playback == null
            ? const Center(child: CircularProgressIndicator())
            : LessonVideoView(player: player, watermark: playback.watermark?.text, isAudio: playback.kind == 'audio'),
      ),
    );
  }
}
