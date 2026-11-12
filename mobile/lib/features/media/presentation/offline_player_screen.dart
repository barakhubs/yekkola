import 'dart:async';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:video_player/video_player.dart';

import '../../../core/i18n/formatters.dart';
import '../../../core/platform/secure_screen.dart';
import '../../../core/sync/sync_providers.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../auth/domain/auth_state.dart';
import '../../auth/presentation/api_error_text.dart';
import '../../auth/presentation/auth_controller.dart';
import '../data/offline_media_server.dart';
import '../domain/offline_download.dart';
import 'downloads_controller.dart';
import 'lesson_video_view.dart';

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
  ProgressTracker? _tracker;
  OfflineDownload? _download;
  _Problem? _problem;
  String? _renewError;
  bool _renewing = false;
  Timer? _expiryCheck;
  late final void Function() _nudge;

  @override
  void initState() {
    super.initState();
    _nudge = ref.read(syncSchedulerProvider).nudge;
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
    ProgressTracker? tracker;
    try {
      key = await ref.read(offlineKeyVaultProvider).unwrap(download.wrappedKey, boundTo: download.keyBinding);
      server = await OfflineMediaServer.start(store: store, lessonUid: download.lessonUid, key: key);
      player = VideoPlayerController.networkUrl(server.playlistUri, formatHint: VideoFormat.hls);
      await player.initialize();
      await store.save(download.openedAt(now));
      if (!mounted) throw StateError('closed');

      final progress = ref.read(progressRepositoryProvider);
      await ProgressTracker.resume(progress, owner.user.id, download.lessonUid, player);
      if (!mounted) throw StateError('closed');
      tracker = ProgressTracker(
        progress: progress,
        userId: owner.user.id,
        lessonUid: download.lessonUid,
        player: player,
        onSaved: _nudge,
      )..start();
      setState(() {
        _download = download;
        _server = server;
        _player = player;
        _tracker = tracker;
      });
      _expiryCheck = Timer.periodic(const Duration(minutes: 1), (_) => _checkExpiry());
      await player.play();
    } catch (_) {
      // Nothing may outlive a failed start: the server holds the plain key.
      if (_player != player) {
        tracker?.stop();
        await player?.dispose();
      }
      if (_server != server) {
        await server?.close();
        if (server == null) key?.fillRange(0, key.length, 0);
      }
      if (mounted && _player == null) setState(() => _problem = _Problem.broken);
    }
  }

  /// A lesson opened just before its expiry stops when the license ends (PRD-07 FR-11).
  Future<void> _checkExpiry() async {
    final download = _download;
    if (download == null || download.isUsableAt(DateTime.now().toUtc())) return;
    _expiryCheck?.cancel();
    final player = _player;
    final server = _server;
    _tracker?.stop();
    setState(() {
      _player = null;
      _server = null;
      _tracker = null;
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
    _tracker?.stop();
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
          _Problem.missing => PlayerMessage(l10n.mediaNotDownloaded),
          _Problem.broken => PlayerMessage(l10n.mediaPlaybackFailed),
          _Problem.expired => PlayerMessage(
            l10n.mediaLicenseExpired,
            detail: _renewError,
            action: FilledButton(onPressed: _renewing ? null : _renew, child: Text(l10n.mediaRenew)),
          ),
          null when player == null => const Center(child: CircularProgressIndicator()),
          null => LessonVideoView(
            player: player!,
            watermark: _download!.watermark,
            isAudio: _download!.kind == 'audio',
            footer: l10n.mediaAvailableUntil(
              Formatters.dateTime(_download!.expiresAt, Localizations.localeOf(context).languageCode),
            ),
          ),
        },
      ),
    );
  }
}
