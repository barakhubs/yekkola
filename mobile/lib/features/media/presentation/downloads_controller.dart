import 'package:drift/drift.dart';

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

enum DownloadPhase { idle, queued, waitingForWifi, waitingForNetwork, downloading, done, failed }

class DownloadEntry {
  const DownloadEntry({this.download, this.labels, this.phase = DownloadPhase.idle, this.progress = 0, this.error});

  final OfflineDownload? download;

  /// Course and lesson titles from the moment the download was asked for (before any manifest exists).
  final DownloadLabels? labels;
  final DownloadPhase phase;
  final double progress;
  final ApiException? error;

  String? get courseId => download?.courseId ?? labels?.courseId;
  String? get courseTitle => download?.courseTitle ?? labels?.courseTitle;
  String? get lessonTitle => download?.lessonTitle ?? labels?.lessonTitle;
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

  static bool online(List<ConnectivityResult> results) => results.any((r) => r != ConnectivityResult.none);
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
      if (row != null && ref.mounted) state = row.value == 'true';
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
  const _Job({required this.userId, required this.lessonUid, this.rendition, this.labels, this.wifiBound = false});

  final String userId;
  final String lessonUid;
  final String? rendition;
  final DownloadLabels? labels;

  /// Resumed by the app on its own (not asked for now): it never uses mobile data, whatever the setting.
  final bool wifiBound;
}

/// Downloads of the signed-in account (PRD-07 FR-08, FR-09): what is on disk, a queue run one at a time,
/// Wi-Fi only, pause/resume, delete. Network drops make the queue wait and continue when the network is back.
/// Interrupted downloads resume by themselves at the next launch on Wi-Fi only (and stay on Wi-Fi); a download
/// the student paused stays paused. Downloads run while the app is alive.
class DownloadsController extends Notifier<Map<String, DownloadEntry>> {
  final _queue = <_Job>[];
  _Job? _active;
  CancelToken? _activeCancel;
  Completer<void>? _activeDone;

  /// Why the active download is being cancelled.
  _Stop? _stopReason;

  /// Paused by the student: never resumed by the app (kept on the phone for the next launch).
  final _userPaused = <String>{};

  /// Being deleted: whatever the cancelled run writes is removed, never shown again.
  final _deleting = <String>{};

  bool _resumedOnce = false;
  int _networkSeq = 0;

  static const _pausedKey = 'download_paused:';

  String? get _userId => switch (ref.read(authControllerProvider)) {
    SignedIn(:final user) => user.id,
    SignedOut() => null,
  };

  @override
  Map<String, DownloadEntry> build() {
    // Another account (or none): stop this account's queue and show only the new account's downloads.
    ref.listen(authControllerProvider.select((a) => a is SignedIn ? a.user.id : null), (_, _) {
      _queue.clear();
      _stop(_Stop.account);
      _resumedOnce = false;
      state = const {};
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
    await _loadUserPaused(stored.map((d) => d.lessonUid));
    if (!ref.mounted || userId != _userId) return;
    state = {
      for (final d in stored)
        d.lessonUid: DownloadEntry(
          download: d,
          phase: d.isComplete ? DownloadPhase.done : DownloadPhase.idle,
          progress: d.segmentCount == 0 ? 0 : d.completedSegments / d.segmentCount,
        ),
      // Keep what is queued or running for this account.
      for (final entry in state.entries)
        if (_isBusy(entry.key)) entry.key: entry.value,
    };

    // Cut short (app closed, phone off): continue on Wi-Fi only, unless the student paused it.
    if (!_resumedOnce && userId != null) {
      _resumedOnce = true;
      final onWifi = NetworkWatch.onWifi(await ref.read(networkWatchProvider).check());
      if (!ref.mounted || !onWifi) return;
      for (final d in stored.where((d) => !d.isComplete && !_userPaused.contains(d.lessonUid))) {
        _add(_Job(userId: userId, lessonUid: d.lessonUid, rendition: d.rendition, wifiBound: true));
      }
      unawaited(_pump());
    }
  }

  bool _isBusy(String uid) => _active?.lessonUid == uid || _queue.any((j) => j.lessonUid == uid);

  /// Adds a download to the queue ([rendition] null: our lowest, PRD-07 FR-08).
  void enqueue(String lessonUid, {String? rendition, DownloadLabels? labels}) {
    final userId = _userId;
    if (userId == null || _isBusy(lessonUid) || _deleting.contains(lessonUid)) return;
    unawaited(_setUserPaused(lessonUid, false));
    _add(_Job(userId: userId, lessonUid: lessonUid, rendition: rendition, labels: labels));
    unawaited(_pump());
  }

  void _add(_Job job) {
    _queue.add(job);
    final current = state[job.lessonUid];
    _set(
      job.lessonUid,
      DownloadEntry(
        download: current?.download,
        labels: job.labels ?? current?.labels,
        phase: DownloadPhase.queued,
        progress: current?.progress ?? 0,
      ),
    );
  }

  /// Same as [enqueue], for callers that start one download.
  Future<void> start(String lessonUid, {String? rendition, DownloadLabels? labels}) async =>
      enqueue(lessonUid, rendition: rendition, labels: labels);

  bool isDownloading(String lessonUid) => _isBusy(lessonUid);

  /// Pauses (or takes out of the queue): finished segments stay; [enqueue] resumes. Stays paused across
  /// network changes and launches.
  void pause(String lessonUid) {
    unawaited(_setUserPaused(lessonUid, true));
    if (_active?.lessonUid == lessonUid) {
      _stop(_Stop.user);
      return;
    }
    _queue.removeWhere((j) => j.lessonUid == lessonUid);
    if (state.containsKey(lessonUid)) _set(lessonUid, _entry(lessonUid, DownloadPhase.idle));
  }

  /// Deletes the download, waiting for a running one to stop so nothing it writes comes back.
  Future<void> delete(String lessonUid) async {
    _deleting.add(lessonUid);
    try {
      _queue.removeWhere((j) => j.lessonUid == lessonUid);
      if (_active?.lessonUid == lessonUid) {
        final done = _activeDone;
        _stop(_Stop.delete);
        await done?.future;
      }
      await ref.read(downloadStoreProvider).delete(lessonUid);
      await _setUserPaused(lessonUid, false);
      if (ref.mounted) state = {...state}..remove(lessonUid);
    } finally {
      _deleting.remove(lessonUid);
    }
  }

  /// Every download of one course (PRD-07 FR-09).
  Future<void> deleteCourse(String? courseId) async {
    for (final entry in [...state.entries]) {
      if (entry.value.courseId == courseId) await delete(entry.key);
    }
  }

  /// Every download of this account (storage full, passing the phone to someone else).
  Future<void> deleteAll() async {
    for (final uid in [...state.keys]) {
      await delete(uid);
    }
  }

  void _stop(_Stop reason) {
    if (_active == null) return;
    // A pause or a delete wins over a network reason.
    if (_stopReason == null || reason == _Stop.user || reason == _Stop.delete || reason == _Stop.account) {
      _stopReason = reason;
    }
    _activeCancel?.cancel();
  }

  Future<List<ConnectivityResult>> _network() => ref.read(networkWatchProvider).check();

  bool _allowed(_Job job, List<ConnectivityResult> network) {
    if (!NetworkWatch.online(network)) return false;
    if (job.wifiBound || ref.read(wifiOnlyProvider)) return NetworkWatch.onWifi(network);
    return true;
  }

  DownloadPhase _waitPhase(_Job job, List<ConnectivityResult> network) =>
      NetworkWatch.online(network) && (job.wifiBound || ref.read(wifiOnlyProvider))
      ? DownloadPhase.waitingForWifi
      : DownloadPhase.waitingForNetwork;

  /// Wi-Fi came or went, the network dropped or returned, or the setting changed. Only the latest check
  /// counts (an older answer arriving late is ignored).
  Future<void> _networkChanged() async {
    if (!ref.mounted) return;
    final seq = ++_networkSeq;
    final network = await _network();
    if (!ref.mounted || seq != _networkSeq) return;

    final active = _active;
    if (active != null && !_allowed(active, network)) _stop(_Stop.network);
    for (final job in _queue) {
      _set(
        job.lessonUid,
        _entry(job.lessonUid, _allowed(job, network) ? DownloadPhase.queued : _waitPhase(job, network)),
      );
    }
    unawaited(_pump());
  }

  Future<void> _pump() async {
    if (!ref.mounted || _active != null || _queue.isEmpty) return;
    final network = await _network();
    if (!ref.mounted || _active != null || _queue.isEmpty) return;
    final userId = _userId;
    final job = _queue.first;
    if (userId == null || job.userId != userId) {
      _queue.removeWhere((j) => j.userId != userId);
      return;
    }
    if (!_allowed(job, network)) {
      for (final waiting in _queue) {
        _set(waiting.lessonUid, _entry(waiting.lessonUid, _waitPhase(waiting, network)));
      }
      return;
    }

    _queue.removeAt(0);
    _active = job;
    _stopReason = null;
    final cancel = _activeCancel = CancelToken();
    final done = _activeDone = Completer<void>();
    _set(job.lessonUid, _entry(job.lessonUid, DownloadPhase.downloading));

    var keepGoing = true;
    try {
      final download = await ref
          .read(hlsDownloaderProvider)
          .download(
            job.lessonUid,
            userId: job.userId,
            rendition: job.rendition,
            labels: job.labels,
            cancel: cancel,
            onProgress: (p) =>
                _set(job.lessonUid, _entry(job.lessonUid, DownloadPhase.downloading, progress: p.fraction)),
          );
      if (_stopReason == null) {
        _set(job.lessonUid, DownloadEntry(download: download, phase: DownloadPhase.done, progress: 1));
      } else {
        await _afterStop(job);
      }
    } on DioException catch (e) {
      if (CancelToken.isCancel(e) || _stopReason != null) {
        keepGoing = await _afterStop(job);
      } else if (e.response == null) {
        keepGoing = await _waitForNetwork(job);
      } else {
        await _settle(job, ApiException.fromDio(e));
      }
    } on ApiException catch (e) {
      if (e.isNetwork) {
        keepGoing = await _waitForNetwork(job);
      } else {
        await _settle(job, e);
      }
    } on FormatException {
      await _settle(job, const ApiException(code: 'offline.unsupported_media', status: 0));
    } catch (_) {
      // Storage full, keystore trouble…: this one fails; the queue goes on.
      await _settle(job, const ApiException(code: 'offline.storage_error', status: 0));
    } finally {
      _active = null;
      _activeCancel = null;
      _stopReason = null;
      done.complete();
    }
    if (keepGoing && ref.mounted) unawaited(_pump());
  }

  /// The run was cancelled: decide what the entry becomes. Returns whether the queue should go on now.
  Future<bool> _afterStop(_Job job) async {
    switch (_stopReason) {
      case _Stop.delete || _Stop.account:
        return true; // delete() / the account switch take it from here
      case _Stop.network:
        // First in line when the network (or Wi-Fi) is back.
        if (job.userId == _userId) _queue.insert(0, job);
        final network = await _network();
        await _settle(job, null, phase: _waitPhase(job, network));
        return false;
      case _Stop.user || null:
        await _settle(job, null);
        return true;
    }
  }

  Future<bool> _waitForNetwork(_Job job) async {
    if (job.userId == _userId) _queue.insert(0, job);
    await _settle(job, null, phase: DownloadPhase.waitingForNetwork);
    return false; // _networkChanged resumes it
  }

  /// A new expiry for a finished download (online only).
  Future<ApiException?> renew(String lessonUid) async {
    final existing = state[lessonUid]?.download ?? await ref.read(downloadStoreProvider).read(lessonUid);
    if (existing == null || existing.userId != _userId) {
      return const ApiException(code: 'offline.not_downloaded', status: 0);
    }
    if (_isBusy(lessonUid)) return const ApiException(code: 'offline.busy', status: 0);
    try {
      final renewed = await ref.read(hlsDownloaderProvider).renew(existing);
      _set(lessonUid, DownloadEntry(download: renewed, phase: DownloadPhase.done, progress: 1));
      return null;
    } on ApiException catch (e) {
      return e;
    } on MediaChanged {
      if (ref.mounted) state = {...state}..remove(lessonUid);
      return const ApiException(code: 'offline.media_changed', status: 0);
    } on FormatException {
      return const ApiException(code: 'offline.unsupported_media', status: 0);
    } on DioException catch (e) {
      return ApiException.fromDio(e);
    }
  }

  DownloadEntry _entry(String lessonUid, DownloadPhase phase, {double? progress}) {
    final current = state[lessonUid];
    return DownloadEntry(
      download: current?.download,
      labels: current?.labels,
      phase: phase,
      progress: progress ?? current?.progress ?? 0,
    );
  }

  Future<void> _settle(_Job job, ApiException? error, {DownloadPhase? phase}) async {
    if (!ref.mounted) return;
    final stored = await ref.read(downloadStoreProvider).read(job.lessonUid);
    if (!ref.mounted) return;
    // Another account signed in meanwhile: not this list's business.
    if (job.userId != _userId || (stored != null && stored.userId != _userId)) return;
    if (_deleting.contains(job.lessonUid)) return;
    _set(
      job.lessonUid,
      DownloadEntry(
        download: stored,
        labels: state[job.lessonUid]?.labels ?? job.labels,
        phase: phase ?? (error == null ? DownloadPhase.idle : DownloadPhase.failed),
        progress: stored == null || stored.segmentCount == 0 ? 0 : stored.completedSegments / stored.segmentCount,
        error: error,
      ),
    );
  }

  Future<void> _loadUserPaused(Iterable<String> uids) async {
    final db = ref.read(appDatabaseProvider);
    final rows = await (db.select(db.syncState)..where((s) => s.key.like('$_pausedKey%'))).get();
    _userPaused
      ..clear()
      ..addAll(rows.map((r) => r.key.substring(_pausedKey.length)));
  }

  Future<void> _setUserPaused(String lessonUid, bool paused) async {
    if (paused) {
      _userPaused.add(lessonUid);
    } else {
      _userPaused.remove(lessonUid);
    }
    if (!ref.mounted) return;
    final db = ref.read(appDatabaseProvider);
    if (paused) {
      await db
          .into(db.syncState)
          .insertOnConflictUpdate(SyncStateCompanion.insert(key: '$_pausedKey$lessonUid', value: '1'));
    } else {
      await (db.delete(db.syncState)..where((s) => s.key.equals('$_pausedKey$lessonUid'))).go();
    }
  }

  void _set(String lessonUid, DownloadEntry entry) {
    if (ref.mounted) state = {...state, lessonUid: entry};
  }
}

enum _Stop { user, network, delete, account }

final downloadsControllerProvider = NotifierProvider<DownloadsController, Map<String, DownloadEntry>>(
  DownloadsController.new,
);
