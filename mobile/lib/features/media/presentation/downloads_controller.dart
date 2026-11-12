import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';

import '../../../core/db/app_database.dart';
import '../../../core/sync/sync_providers.dart';

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

enum DownloadPhase { idle, queued, waitingForWifi, downloading, done, failed }

class DownloadEntry {
  const DownloadEntry({this.download, this.phase = DownloadPhase.idle, this.progress = 0, this.error});

  final OfflineDownload? download;
  final DownloadPhase phase;
  final double progress;
  final ApiException? error;
}

/// The network as the downloads see it; replaceable in tests.
class NetworkWatch {
  NetworkWatch({Future<List<ConnectivityResult>> Function()? check, Stream<List<ConnectivityResult>>? changes})
    : check = check ?? Connectivity().checkConnectivity,
      changes = changes ?? Connectivity().onConnectivityChanged;

  final Future<List<ConnectivityResult>> Function() check;
  final Stream<List<ConnectivityResult>> changes;

  static bool onWifi(List<ConnectivityResult> results) =>
      results.contains(ConnectivityResult.wifi) || results.contains(ConnectivityResult.ethernet);
}

final networkWatchProvider = Provider<NetworkWatch>((ref) => NetworkWatch());

/// "Download on Wi-Fi only" (PRD-07 FR-09), kept on this phone. Off by default: many students only have
/// mobile data; when on, downloads wait for Wi-Fi and pause when it goes.
class WifiOnly extends Notifier<bool> {
  static const _key = 'setting:downloads_wifi_only';

  @override
  bool build() {
    Future.microtask(() async {
      final db = ref.read(appDatabaseProvider);
      final row = await (db.select(db.syncState)..where((s) => s.key.equals(_key))).getSingleOrNull();
      if (row != null) state = row.value == 'true';
    });
    return false;
  }

  Future<void> set(bool value) async {
    state = value;
    final db = ref.read(appDatabaseProvider);
    await db.into(db.syncState).insertOnConflictUpdate(SyncStateCompanion.insert(key: _key, value: '$value'));
  }
}

final wifiOnlyProvider = NotifierProvider<WifiOnly, bool>(WifiOnly.new);

class _Job {
  const _Job(this.lessonUid, this.rendition, this.labels);

  final String lessonUid;
  final String? rendition;
  final DownloadLabels? labels;
}

/// Downloads of the signed-in account (PRD-07 FR-08, FR-09): what is on disk, a queue run one at a time,
/// Wi-Fi only, pause/resume, delete. Interrupted downloads resume by themselves at the next launch on Wi-Fi.
/// Downloads run while the app is open (in the background as long as the system lets the app live).
class DownloadsController extends Notifier<Map<String, DownloadEntry>> {
  final _queue = <_Job>[];
  String? _active;
  CancelToken? _activeCancel;

  /// Why the active download was cancelled: by the user (stays paused) or for Wi-Fi (waits).
  bool _cancelledForWifi = false;
  bool _resumedOnce = false;

  /// The signed-in account; downloads of other accounts on this phone are neither listed nor played.
  String? get _userId => switch (ref.read(authControllerProvider)) {
    SignedIn(:final user) => user.id,
    SignedOut() => null,
  };

  @override
  Map<String, DownloadEntry> build() {
    // Another account (or none): show only that account's downloads, and stop the other's queue.
    ref.listen(authControllerProvider.select((a) => a is SignedIn ? a.user.id : null), (_, _) {
      _queue.clear();
      _activeCancel?.cancel();
      _resumedOnce = false;
      unawaited(reload());
    });
    ref.listen(wifiOnlyProvider, (_, _) => unawaited(_networkChanged()));
    final network = ref.read(networkWatchProvider).changes.listen((_) => unawaited(_networkChanged()));
    ref.onDispose(() {
      unawaited(network.cancel());
      _activeCancel?.cancel();
    });
    Future.microtask(reload);
    return const {};
  }

  Future<void> reload() async {
    if (!ref.mounted) return;
    final userId = _userId;
    final stored = (await ref.read(downloadStoreProvider).all()).where((d) => d.userId == userId).toList();
    if (!ref.mounted) return;
    state = {
      for (final d in stored)
        d.lessonUid: DownloadEntry(
          download: d,
          phase: d.isComplete ? DownloadPhase.done : DownloadPhase.idle,
          progress: d.segmentCount == 0 ? 0 : d.completedSegments / d.segmentCount,
        ),
      // Keep what is queued or running.
      for (final entry in state.entries)
        if (_isBusy(entry.key)) entry.key: entry.value,
    };

    // Downloads cut short (app closed, phone off) continue by themselves — on Wi-Fi only, so mobile data is
    // never spent without the student asking.
    if (!_resumedOnce && userId != null) {
      _resumedOnce = true;
      final onWifi = NetworkWatch.onWifi(await ref.read(networkWatchProvider).check());
      if (!ref.mounted) return;
      if (onWifi) {
        for (final d in stored.where((d) => !d.isComplete)) {
          enqueue(d.lessonUid, rendition: d.rendition);
        }
      }
    }
  }

  bool _isBusy(String uid) => _active == uid || _queue.any((j) => j.lessonUid == uid);

  /// Adds a download to the queue ([rendition] null: our lowest, PRD-07 FR-08).
  void enqueue(String lessonUid, {String? rendition, DownloadLabels? labels}) {
    if (_userId == null || _isBusy(lessonUid)) return;
    _queue.add(_Job(lessonUid, rendition, labels));
    _set(lessonUid, _entry(lessonUid, DownloadPhase.queued));
    unawaited(_pump());
  }

  /// Same as [enqueue], for callers that start one download.
  Future<void> start(String lessonUid, {String? rendition, DownloadLabels? labels}) async =>
      enqueue(lessonUid, rendition: rendition, labels: labels);

  bool isDownloading(String lessonUid) => _isBusy(lessonUid);

  /// Pauses (or takes out of the queue): finished segments stay; [enqueue] resumes.
  void pause(String lessonUid) {
    if (_active == lessonUid) {
      _cancelledForWifi = false;
      _activeCancel?.cancel();
      return;
    }
    _queue.removeWhere((j) => j.lessonUid == lessonUid);
    if (state.containsKey(lessonUid)) _set(lessonUid, _entry(lessonUid, DownloadPhase.idle));
  }

  Future<void> delete(String lessonUid) async {
    pause(lessonUid);
    await ref.read(downloadStoreProvider).delete(lessonUid);
    state = {...state}..remove(lessonUid);
  }

  /// Every download of this account (storage full, passing the phone to someone else).
  Future<void> deleteAll() async {
    for (final uid in [...state.keys]) {
      await delete(uid);
    }
  }

  Future<bool> _mayDownloadNow() async {
    if (!ref.read(wifiOnlyProvider)) return true;
    return NetworkWatch.onWifi(await ref.read(networkWatchProvider).check());
  }

  /// Wi-Fi came or went, or the setting changed.
  Future<void> _networkChanged() async {
    if (!ref.mounted) return;
    final allowed = await _mayDownloadNow();
    if (!ref.mounted) return;
    if (!allowed) {
      if (_active != null) {
        _cancelledForWifi = true;
        _activeCancel?.cancel();
      }
      for (final job in _queue) {
        _set(job.lessonUid, _entry(job.lessonUid, DownloadPhase.waitingForWifi));
      }
      return;
    }
    for (final job in _queue) {
      _set(job.lessonUid, _entry(job.lessonUid, DownloadPhase.queued));
    }
    unawaited(_pump());
  }

  Future<void> _pump() async {
    if (!ref.mounted || _active != null || _queue.isEmpty) return;
    final allowed = await _mayDownloadNow();
    if (!ref.mounted) return;
    if (!allowed) {
      for (final job in _queue) {
        _set(job.lessonUid, _entry(job.lessonUid, DownloadPhase.waitingForWifi));
      }
      return;
    }
    final userId = _userId;
    if (_active != null || _queue.isEmpty || userId == null) return;
    final job = _queue.removeAt(0);
    _active = job.lessonUid;
    final cancel = _activeCancel = CancelToken();
    _cancelledForWifi = false;
    _set(job.lessonUid, _entry(job.lessonUid, DownloadPhase.downloading));

    try {
      final download = await ref
          .read(hlsDownloaderProvider)
          .download(
            job.lessonUid,
            userId: userId,
            rendition: job.rendition,
            labels: job.labels,
            cancel: cancel,
            onProgress: (p) => _set(
              job.lessonUid,
              DownloadEntry(
                download: state[job.lessonUid]?.download,
                phase: DownloadPhase.downloading,
                progress: p.fraction,
              ),
            ),
          );
      _set(job.lessonUid, DownloadEntry(download: download, phase: DownloadPhase.done, progress: 1));
    } on DioException catch (e) {
      if (CancelToken.isCancel(e)) {
        if (_cancelledForWifi) {
          // Waits for Wi-Fi at the front of the queue.
          _queue.insert(0, job);
          await _settle(job.lessonUid, null, phase: DownloadPhase.waitingForWifi);
        } else {
          await _settle(job.lessonUid, null);
        }
      } else {
        await _settle(job.lessonUid, ApiException.fromDio(e));
      }
    } on ApiException catch (e) {
      await _settle(job.lessonUid, e);
    } on FormatException {
      await _settle(job.lessonUid, const ApiException(code: 'offline.unsupported_media', status: 0));
    } finally {
      _active = null;
      _activeCancel = null;
    }
    if (ref.mounted) unawaited(_pump());
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

  DownloadEntry _entry(String lessonUid, DownloadPhase phase) {
    final current = state[lessonUid];
    return DownloadEntry(download: current?.download, phase: phase, progress: current?.progress ?? 0);
  }

  Future<void> _settle(String lessonUid, ApiException? error, {DownloadPhase? phase}) async {
    if (!ref.mounted) return;
    final stored = await ref.read(downloadStoreProvider).read(lessonUid);
    if (!ref.mounted) return;
    _set(
      lessonUid,
      DownloadEntry(
        download: stored,
        phase: phase ?? (error == null ? DownloadPhase.idle : DownloadPhase.failed),
        progress: stored == null || stored.segmentCount == 0 ? 0 : stored.completedSegments / stored.segmentCount,
        error: error,
      ),
    );
  }

  void _set(String lessonUid, DownloadEntry entry) {
    if (ref.mounted) state = {...state, lessonUid: entry};
  }
}

final downloadsControllerProvider = NotifierProvider<DownloadsController, Map<String, DownloadEntry>>(
  DownloadsController.new,
);
