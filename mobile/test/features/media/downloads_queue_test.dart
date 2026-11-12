import 'dart:async';
import 'dart:io';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:dio/dio.dart';
import 'package:drift/drift.dart' hide isNull, isNotNull;
import 'package:drift/native.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:yekkola/core/api/generated/yekkola_api.dart';
import 'package:yekkola/core/db/app_database.dart';
import 'package:yekkola/core/storage/secure_store.dart';
import 'package:yekkola/core/sync/sync_providers.dart';
import 'package:yekkola/features/auth/domain/app_user.dart';
import 'package:yekkola/features/auth/domain/auth_state.dart';
import 'package:yekkola/features/media/data/download_store.dart';
import 'package:yekkola/features/media/data/hls_downloader.dart';
import 'package:yekkola/features/media/data/offline_key_vault.dart';
import 'package:yekkola/features/media/domain/offline_download.dart';
import 'package:yekkola/features/media/presentation/downloads_controller.dart';

import '../../support/fake_api.dart';

/// Downloads that finish only when the test says so (or fail with a cancel, like the real one).
class _ScriptedDownloader extends HlsDownloader {
  _ScriptedDownloader(DownloadStore store)
    : super(api: YekkolaApi(Dio()), segments: Dio(), store: store, vault: OfflineKeyVault(MemorySecureStore()));

  final started = <String>[];
  final _done = <String, Completer<OfflineDownload>>{};

  void finish(String uid) => _done[uid]!.complete(_download(uid, complete: true));

  @override
  Future<OfflineDownload> download(
    String lessonUid, {
    required String userId,
    DownloadLabels? labels,
    String? rendition,
    CancelToken? cancel,
    void Function(DownloadProgress progress)? onProgress,
  }) {
    started.add(lessonUid);
    final done = _done[lessonUid] = Completer<OfflineDownload>();
    cancel?.whenCancel.then((e) {
      if (!done.isCompleted) done.completeError(e);
    });
    return done.future;
  }
}

OfflineDownload _download(String uid, {required bool complete}) => OfflineDownload(
  userId: '01JUSER00000000000000000000',
  lessonUid: uid,
  mediaId: 'm',
  licenseId: 'l',
  kind: 'video',
  rendition: '360p',
  sizeBytes: 100,
  segmentCount: 4,
  expiresAt: DateTime.utc(2099),
  wrappedKey: const WrappedKey(nonce: [], cipherText: [], mac: []),
  watermark: 'w',
  completedSegments: complete ? 4 : 1,
);

const _a = '01JLESSONAAAAAAAAAAAAAAAAA';
const _b = '01JLESSONBBBBBBBBBBBBBBBBB';

void main() {
  late Directory root;
  late DownloadStore store;
  late _ScriptedDownloader downloader;
  late StreamController<List<ConnectivityResult>> network;
  late List<ConnectivityResult> now;

  setUp(() async {
    root = await Directory.systemTemp.createTemp('yekkola_queue_');
    store = DownloadStore(root);
    downloader = _ScriptedDownloader(store);
    network = StreamController.broadcast();
    now = [ConnectivityResult.wifi];
  });

  tearDown(() async {
    await network.close();
    await root.delete(recursive: true);
  });

  ProviderContainer container() {
    final c = makeContainer(
      FakeApi((_, _) => const FakeResponse(500)),
      initial: SignedIn(user: AppUser.fromJson(userJson()), token: 't'),
      extra: [
        downloadsRootProvider.overrideWithValue(root),
        hlsDownloaderProvider.overrideWithValue(downloader),
        networkWatchProvider.overrideWithValue(NetworkWatch(check: () async => now, changes: network.stream)),
        appDatabaseProvider.overrideWith((ref) {
          final db = AppDatabase(DatabaseConnection(NativeDatabase.memory(), closeStreamsSynchronously: true));
          ref.onDispose(db.close);
          return db;
        }),
      ],
    );
    c.listen(downloadsControllerProvider, (_, _) {});
    return c;
  }

  Future<void> settle() => Future<void>.delayed(const Duration(milliseconds: 30));

  /// Waits for [check] (up to 3 s): async work finishes later on a busy machine.
  Future<void> until(bool Function() check) async {
    for (var i = 0; i < 300 && !check(); i++) {
      await Future<void>.delayed(const Duration(milliseconds: 10));
    }
  }

  test('runs one download at a time, in order', () async {
    final c = container();
    final downloads = c.read(downloadsControllerProvider.notifier);
    await settle();

    downloads.enqueue(_a);
    downloads.enqueue(_b);
    await until(() => downloader.started.isNotEmpty);
    await settle();
    expect(downloader.started, [_a]);
    expect(c.read(downloadsControllerProvider)[_b]?.phase, DownloadPhase.queued);

    downloader.finish(_a);
    await until(() => downloader.started.length == 2);
    expect(downloader.started, [_a, _b]);
    expect(c.read(downloadsControllerProvider)[_a]?.phase, DownloadPhase.done);
  });

  test('Wi-Fi only: waits for Wi-Fi, pauses when it goes, resumes when it comes back (PRD-07 FR-09)', () async {
    final c = container();
    await c.read(wifiOnlyProvider.notifier).set(true);
    final downloads = c.read(downloadsControllerProvider.notifier);
    await settle();

    now = [ConnectivityResult.mobile];
    downloads.enqueue(_a);
    await settle();
    expect(downloader.started, isEmpty);
    expect(c.read(downloadsControllerProvider)[_a]?.phase, DownloadPhase.waitingForWifi);

    now = [ConnectivityResult.wifi];
    network.add(now);
    await until(() => downloader.started.length == 1);
    expect(downloader.started, [_a]);

    // Wi-Fi lost mid-download: paused, first in line when it comes back.
    now = [ConnectivityResult.mobile];
    network.add(now);
    await until(() => c.read(downloadsControllerProvider)[_a]?.phase == DownloadPhase.waitingForWifi);
    expect(c.read(downloadsControllerProvider)[_a]?.phase, DownloadPhase.waitingForWifi);

    now = [ConnectivityResult.wifi];
    network.add(now);
    await until(() => downloader.started.length == 2);
    expect(downloader.started, [_a, _a]);
  });

  test('pausing stops the download and takes it out of the queue', () async {
    final c = container();
    final downloads = c.read(downloadsControllerProvider.notifier);
    await settle();

    downloads.enqueue(_a);
    downloads.enqueue(_b);
    await settle();
    downloads.pause(_b); // queued: removed
    downloads.pause(_a); // running: cancelled, stays paused
    await settle();

    expect(downloader.started, [_a]);
    expect(c.read(downloadsControllerProvider)[_b]?.phase, DownloadPhase.idle);
    expect(downloads.isDownloading(_a), isFalse);
  });

  test('a download cut short resumes by itself at launch — on Wi-Fi, never on mobile data', () async {
    await store.save(_download(_a, complete: false));

    now = [ConnectivityResult.mobile];
    final onData = container();
    await settle();
    expect(downloader.started, isEmpty);
    expect(onData.read(downloadsControllerProvider)[_a]?.phase, DownloadPhase.idle);
    onData.dispose();

    now = [ConnectivityResult.wifi];
    container();
    await until(() => downloader.started.isNotEmpty);
    expect(downloader.started, [_a]);
  });
}
