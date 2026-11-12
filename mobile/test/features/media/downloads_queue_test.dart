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
import 'package:yekkola/features/auth/presentation/auth_controller.dart';
import 'package:yekkola/features/media/data/download_store.dart';
import 'package:yekkola/features/media/data/hls_downloader.dart';
import 'package:yekkola/features/media/data/offline_key_vault.dart';
import 'package:yekkola/features/media/domain/offline_download.dart';
import 'package:yekkola/features/media/presentation/downloads_controller.dart';

import '../../support/fake_api.dart';

/// Downloads that finish only when the test says so (or fail with a cancel, like the real one).
class _ScriptedDownloader extends HlsDownloader {
  _ScriptedDownloader(DownloadStore store)
    : store0x = store,
      super(api: YekkolaApi(Dio()), segments: Dio(), store: store, vault: OfflineKeyVault(MemorySecureStore()));

  final started = <String>[];
  final _done = <String, Completer<OfflineDownload>>{};
  final DownloadStore store0x;

  void finish(String uid) => _done[uid]!.complete(_download(uid, complete: true));

  void fail(String uid, Object error) => _done[uid]!.completeError(error);

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
    cancel?.whenCancel.then((e) async {
      // Like the real downloader: a write still in flight lands after the cancel.
      try {
        await store0x.save(_download(lessonUid, complete: false));
      } on FileSystemException {
        // The test already cleaned up.
      }
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
  late AppDatabase db;
  final containers = <ProviderContainer>[];

  setUp(() async {
    root = await Directory.systemTemp.createTemp('yekkola_queue_');
    store = DownloadStore(root);
    downloader = _ScriptedDownloader(store);
    network = StreamController.broadcast();
    now = [ConnectivityResult.wifi];
    // One phone database across "launches" (containers), like the real app.
    db = AppDatabase(DatabaseConnection(NativeDatabase.memory(), closeStreamsSynchronously: true));
  });

  tearDown(() async {
    // Stop the controllers (and their running downloads) before removing their files.
    for (final c in containers) {
      c.dispose();
    }
    containers.clear();
    await Future<void>.delayed(const Duration(milliseconds: 50));
    await network.close();
    await db.close();
    try {
      await root.delete(recursive: true);
    } on FileSystemException {
      // A late write from a cancelled fake download; the temp folder is cleaned by the system.
    }
  });

  ProviderContainer container() {
    final c = makeContainer(
      FakeApi((_, _) => const FakeResponse(500)),
      initial: SignedIn(user: AppUser.fromJson(userJson()), token: 't'),
      extra: [
        downloadsRootProvider.overrideWithValue(root),
        hlsDownloaderProvider.overrideWithValue(downloader),
        networkWatchProvider.overrideWithValue(NetworkWatch(check: () async => now, changes: network.stream)),
        appDatabaseProvider.overrideWithValue(db),
      ],
    );
    c.listen(downloadsControllerProvider, (_, _) {});
    containers.add(c);
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
    await until(() => downloader.started.isNotEmpty);
    downloads.pause(_b); // queued: removed
    downloads.pause(_a); // running: cancelled, stays paused
    await until(() => !downloads.isDownloading(_a));
    await settle();

    expect(downloader.started, [_a]);
    expect(c.read(downloadsControllerProvider)[_b]?.phase, DownloadPhase.idle);
    expect(downloads.isDownloading(_a), isFalse);
  });

  test('a network drop makes the queue wait, then it continues (Wi-Fi only off)', () async {
    final c = container();
    final downloads = c.read(downloadsControllerProvider.notifier);
    await settle();

    downloads.enqueue(_a);
    downloads.enqueue(_b);
    await until(() => downloader.started.isNotEmpty);
    now = [ConnectivityResult.none];
    downloader.fail(
      _a,
      DioException(
        requestOptions: RequestOptions(path: '/s'),
        type: DioExceptionType.connectionError,
      ),
    );
    await until(() => c.read(downloadsControllerProvider)[_a]?.phase == DownloadPhase.waitingForNetwork);
    await settle();
    expect(downloader.started, [_a], reason: 'nothing else is tried while offline');
    expect(c.read(downloadsControllerProvider)[_b]?.phase, isNot(DownloadPhase.failed));

    now = [ConnectivityResult.mobile];
    network.add(now);
    await until(() => downloader.started.length == 2);
    expect(downloader.started, [_a, _a]);
  });

  test('an unexpected failure (storage full) fails that download and the queue goes on', () async {
    final c = container();
    final downloads = c.read(downloadsControllerProvider.notifier);
    await settle();

    downloads.enqueue(_a);
    downloads.enqueue(_b);
    await until(() => downloader.started.isNotEmpty);
    downloader.fail(_a, const FileSystemException('No space left on device'));
    await until(() => downloader.started.length == 2);

    expect(c.read(downloadsControllerProvider)[_a]?.phase, DownloadPhase.failed);
    expect(c.read(downloadsControllerProvider)[_a]?.error?.code, 'offline.storage_error');
    expect(downloader.started, [_a, _b]);
  });

  test('a download deleted while running does not come back', () async {
    final c = container();
    final downloads = c.read(downloadsControllerProvider.notifier);
    await settle();

    downloads.enqueue(_a);
    await until(() => downloader.started.isNotEmpty);
    await downloads.delete(_a);
    await settle();

    expect(c.read(downloadsControllerProvider).containsKey(_a), isFalse);
    expect(await store.read(_a), isNull);
  });

  test("another account signing in never shows or continues the first account's download", () async {
    final c = container();
    final downloads = c.read(downloadsControllerProvider.notifier);
    await settle();

    downloads.enqueue(_a);
    downloads.enqueue(_b);
    await until(() => downloader.started.isNotEmpty);
    final other = AppUser.fromJson({...userJson(), 'id': '01JOTHERUSER00000000000000'});
    c.read(authControllerProvider.notifier).state = SignedIn(user: other, token: 't2');
    await settle();
    await settle();

    expect(c.read(downloadsControllerProvider), isEmpty);
    expect(downloader.started, [_a], reason: "the first account's queue does not run for the second");
  });

  test('a download the student paused stays paused at the next launch', () async {
    await store.save(_download(_a, complete: false));
    final first = container();
    await until(() => downloader.started.isNotEmpty);
    first.read(downloadsControllerProvider.notifier).pause(_a);
    await settle();
    first.dispose();

    downloader.started.clear();
    container();
    await settle();
    await settle();
    expect(downloader.started, isEmpty);
  });

  test('a download cut short resumes by itself at launch — on Wi-Fi, never on mobile data', () async {
    await store.save(_download(_a, complete: false));

    now = [ConnectivityResult.mobile];
    final onData = container();
    await until(() => onData.read(downloadsControllerProvider).containsKey(_a));
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
