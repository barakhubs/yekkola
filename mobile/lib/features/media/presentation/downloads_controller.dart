import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/providers.dart';
import '../../auth/domain/auth_state.dart';
import '../../auth/presentation/auth_controller.dart';
import '../data/download_store.dart';
import '../data/hls_downloader.dart';
import '../data/offline_key_vault.dart';
import '../domain/offline_download.dart';

/// App-private folder for downloads, resolved in main() before the first frame.
final downloadsRootProvider = Provider<Directory>((ref) => throw UnimplementedError('overridden in main'));

final downloadStoreProvider = Provider<DownloadStore>((ref) => DownloadStore(ref.watch(downloadsRootProvider)));

final offlineKeyVaultProvider = Provider<OfflineKeyVault>((ref) => OfflineKeyVault(ref.watch(secureStoreProvider)));

/// For signed storage URLs only: none of the API client's interceptors or headers.
final segmentDioProvider = Provider<Dio>((ref) {
  final dio = Dio(
    BaseOptions(connectTimeout: const Duration(seconds: 20), receiveTimeout: const Duration(seconds: 60)),
  );
  ref.onDispose(dio.close);
  return dio;
});

final hlsDownloaderProvider = Provider<HlsDownloader>(
  (ref) => HlsDownloader(
    api: ref.watch(yekkolaApiProvider),
    segments: ref.watch(segmentDioProvider),
    store: ref.watch(downloadStoreProvider),
    vault: ref.watch(offlineKeyVaultProvider),
  ),
);

enum DownloadPhase { idle, downloading, done, failed }

class DownloadEntry {
  const DownloadEntry({this.download, this.phase = DownloadPhase.idle, this.progress = 0, this.error});

  final OfflineDownload? download;
  final DownloadPhase phase;
  final double progress;
  final ApiException? error;
}

/// Downloads by lesson: what is on disk and what is in flight. Prototype scope (PRD-07 FR-16): one queue
/// per lesson in the foreground; the full manager (queue, Wi-Fi only, background, storage) comes next.
class DownloadsController extends Notifier<Map<String, DownloadEntry>> {
  final _cancels = <String, CancelToken>{};

  /// The signed-in account; downloads of other accounts on this phone are neither listed nor played.
  String? get _userId => switch (ref.read(authControllerProvider)) {
    SignedIn(:final user) => user.id,
    SignedOut() => null,
  };

  @override
  Map<String, DownloadEntry> build() {
    // Another account (or none): show only that account's downloads.
    ref.listen(authControllerProvider.select((a) => a is SignedIn ? a.user.id : null), (_, _) => reload());
    ref.onDispose(() {
      for (final cancel in _cancels.values) {
        cancel.cancel();
      }
    });
    Future.microtask(reload);
    return const {};
  }

  Future<void> reload() async {
    final userId = _userId;
    final stored = (await ref.read(downloadStoreProvider).all()).where((d) => d.userId == userId);
    state = {
      for (final d in stored)
        d.lessonUid: DownloadEntry(
          download: d,
          phase: d.isComplete ? DownloadPhase.done : DownloadPhase.idle,
          progress: d.segmentCount == 0 ? 0 : d.completedSegments / d.segmentCount,
        ),
      for (final entry in state.entries)
        if (entry.value.phase == DownloadPhase.downloading) entry.key: entry.value,
    };
  }

  Future<void> start(String lessonUid, {String? rendition}) async {
    final userId = _userId;
    if (userId == null || _cancels.containsKey(lessonUid)) return;
    final cancel = _cancels[lessonUid] = CancelToken();
    _set(lessonUid, DownloadEntry(download: state[lessonUid]?.download, phase: DownloadPhase.downloading));

    try {
      final download = await ref
          .read(hlsDownloaderProvider)
          .download(
            lessonUid,
            userId: userId,
            rendition: rendition,
            cancel: cancel,
            onProgress: (p) => _set(lessonUid, DownloadEntry(phase: DownloadPhase.downloading, progress: p.fraction)),
          );
      _set(lessonUid, DownloadEntry(download: download, phase: DownloadPhase.done, progress: 1));
    } on DioException catch (e) {
      final stopped = CancelToken.isCancel(e);
      await _settle(lessonUid, stopped ? null : ApiException.fromDio(e));
    } on ApiException catch (e) {
      await _settle(lessonUid, e);
    } on FormatException {
      await _settle(lessonUid, const ApiException(code: 'offline.unsupported_media', status: 0));
    } finally {
      _cancels.remove(lessonUid);
    }
  }

  bool isDownloading(String lessonUid) => _cancels.containsKey(lessonUid);

  /// Pauses: finished segments stay; [start] resumes.
  void pause(String lessonUid) => _cancels[lessonUid]?.cancel();

  Future<void> delete(String lessonUid) async {
    pause(lessonUid);
    await ref.read(downloadStoreProvider).delete(lessonUid);
    state = {...state}..remove(lessonUid);
  }

  /// A new expiry for a finished download (online only).
  Future<ApiException?> renew(String lessonUid) async {
    final existing = state[lessonUid]?.download ?? await ref.read(downloadStoreProvider).read(lessonUid);
    if (existing == null || existing.userId != _userId) {
      return const ApiException(code: 'offline.not_downloaded', status: 0);
    }
    try {
      final renewed = await ref.read(hlsDownloaderProvider).renew(existing);
      _set(lessonUid, DownloadEntry(download: renewed, phase: DownloadPhase.done, progress: 1));
      return null;
    } on ApiException catch (e) {
      return e;
    } on MediaChanged {
      state = {...state}..remove(lessonUid);
      return const ApiException(code: 'offline.media_changed', status: 0);
    } on FormatException {
      return const ApiException(code: 'offline.unsupported_media', status: 0);
    } on DioException catch (e) {
      return ApiException.fromDio(e);
    }
  }

  Future<void> _settle(String lessonUid, ApiException? error) async {
    final stored = await ref.read(downloadStoreProvider).read(lessonUid);
    _set(
      lessonUid,
      DownloadEntry(
        download: stored,
        phase: error == null ? DownloadPhase.idle : DownloadPhase.failed,
        progress: stored == null || stored.segmentCount == 0 ? 0 : stored.completedSegments / stored.segmentCount,
        error: error,
      ),
    );
  }

  void _set(String lessonUid, DownloadEntry entry) => state = {...state, lessonUid: entry};
}

final downloadsControllerProvider = NotifierProvider<DownloadsController, Map<String, DownloadEntry>>(
  DownloadsController.new,
);
