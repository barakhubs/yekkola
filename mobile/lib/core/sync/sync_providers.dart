import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../features/auth/domain/auth_state.dart';
import '../../features/auth/presentation/auth_controller.dart';
import '../../features/learning/data/progress_sync.dart';
import '../../features/media/data/entitlement_sync.dart';
import '../../features/media/presentation/downloads_controller.dart';
import '../db/app_database.dart';
import '../providers.dart';
import 'outbox.dart';
import 'sync_engine.dart';

final appDatabaseProvider = Provider<AppDatabase>((ref) {
  final db = AppDatabase();
  ref.onDispose(db.close);
  return db;
});

final outboxStoreProvider = Provider<OutboxStore>((ref) => OutboxStore(ref.watch(appDatabaseProvider)));

final progressRepositoryProvider = Provider<ProgressRepository>(
  (ref) => ProgressRepository(ref.watch(appDatabaseProvider), ref.watch(outboxStoreProvider)),
);

final syncEngineProvider = Provider<SyncEngine>(
  (ref) => SyncEngine(
    outbox: ref.watch(outboxStoreProvider),
    handlers: [ProgressOutboxHandler(ref.watch(dioProvider))],
    currentUserId: () => switch (ref.read(authControllerProvider)) {
      SignedIn(:final user) => user.id,
      SignedOut() => null,
    },
  ),
);

/// When to sync: at start, when the network comes back, when the app returns to the foreground, every minute
/// while open, after a sign-in, and shortly after a write ([nudge]). One request per kind per run, so this
/// stays cheap on data. [entitlements] (download checks, PRD-07 FR-10) runs on start, reconnect, resume and
/// sign-in; it throttles itself.
class SyncScheduler with WidgetsBindingObserver {
  SyncScheduler(this._engine, {this.entitlements, Stream<List<ConnectivityResult>>? connectivity})
    : _connectivity = connectivity ?? Connectivity().onConnectivityChanged;

  final SyncEngine _engine;
  final Future<void> Function()? entitlements;
  Future<void>? _checking;

  /// Never two checks at once, and never an error out of a check (callers don't await it).
  void checkEntitlements() {
    final job = entitlements;
    if (job == null || _checking != null) return;
    _checking = job().catchError((Object _) {}).whenComplete(() => _checking = null);
  }

  final Stream<List<ConnectivityResult>> _connectivity;
  StreamSubscription<List<ConnectivityResult>>? _network;
  Timer? _periodic;
  Timer? _debounce;

  void start() {
    WidgetsBinding.instance.addObserver(this);
    _network = _connectivity.listen((results) {
      if (results.any((r) => r != ConnectivityResult.none)) {
        unawaited(_engine.flush());
        checkEntitlements();
      }
    });
    _periodic = Timer.periodic(const Duration(minutes: 1), (_) => unawaited(_engine.flush()));
    unawaited(_engine.flush());
    checkEntitlements();
  }

  /// A write was queued: send it soon, coalescing bursts (the player saves every 15 s).
  void nudge() {
    _debounce?.cancel();
    _debounce = Timer(const Duration(seconds: 3), () => unawaited(_engine.flush()));
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      unawaited(_engine.flush());
      checkEntitlements();
    }
  }

  void stop() {
    WidgetsBinding.instance.removeObserver(this);
    unawaited(_network?.cancel());
    _periodic?.cancel();
    _debounce?.cancel();
  }
}

final entitlementSyncProvider = Provider<EntitlementSync>(
  (ref) => EntitlementSync(
    api: ref.watch(yekkolaApiProvider),
    store: ref.watch(downloadStoreProvider),
    downloader: ref.watch(hlsDownloaderProvider),
    db: ref.watch(appDatabaseProvider),
    isDownloading: (uid) => ref.read(downloadsControllerProvider.notifier).isDownloading(uid),
  ),
);

final syncSchedulerProvider = Provider<SyncScheduler>((ref) {
  final scheduler = SyncScheduler(
    ref.watch(syncEngineProvider),
    entitlements: () async {
      final auth = ref.read(authControllerProvider);
      if (auth is! SignedIn) return;
      final result = await ref.read(entitlementSyncProvider).run(auth.user.id);
      if (result.deleted.isNotEmpty || result.renewed.isNotEmpty) {
        await ref.read(downloadsControllerProvider.notifier).reload();
      }
    },
  )..start();
  ref.listen(authControllerProvider, (previous, next) {
    if (previous is! SignedIn && next is SignedIn) {
      scheduler.nudge();
      scheduler.checkEntitlements();
    }
  });
  ref.onDispose(scheduler.stop);
  return scheduler;
});
