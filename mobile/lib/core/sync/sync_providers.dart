import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../features/auth/domain/auth_state.dart';
import '../../features/auth/presentation/auth_controller.dart';
import '../../features/learning/data/progress_sync.dart';
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
/// stays cheap on data.
class SyncScheduler with WidgetsBindingObserver {
  SyncScheduler(this._engine, {Stream<List<ConnectivityResult>>? connectivity})
    : _connectivity = connectivity ?? Connectivity().onConnectivityChanged;

  final SyncEngine _engine;
  final Stream<List<ConnectivityResult>> _connectivity;
  StreamSubscription<List<ConnectivityResult>>? _network;
  Timer? _periodic;
  Timer? _debounce;

  void start() {
    WidgetsBinding.instance.addObserver(this);
    _network = _connectivity.listen((results) {
      if (results.any((r) => r != ConnectivityResult.none)) unawaited(_engine.flush());
    });
    _periodic = Timer.periodic(const Duration(minutes: 1), (_) => unawaited(_engine.flush()));
    unawaited(_engine.flush());
  }

  /// A write was queued: send it soon, coalescing bursts (the player saves every 15 s).
  void nudge() {
    _debounce?.cancel();
    _debounce = Timer(const Duration(seconds: 3), () => unawaited(_engine.flush()));
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) unawaited(_engine.flush());
  }

  void stop() {
    WidgetsBinding.instance.removeObserver(this);
    unawaited(_network?.cancel());
    _periodic?.cancel();
    _debounce?.cancel();
  }
}

final syncSchedulerProvider = Provider<SyncScheduler>((ref) {
  final scheduler = SyncScheduler(ref.watch(syncEngineProvider))..start();
  ref.listen(authControllerProvider, (previous, next) {
    if (previous is! SignedIn && next is SignedIn) scheduler.nudge();
  });
  ref.onDispose(scheduler.stop);
  return scheduler;
});
