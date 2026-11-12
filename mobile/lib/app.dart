import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'core/i18n/locale_controller.dart';
import 'core/providers.dart';
import 'core/router/app_router.dart';
import 'core/sync/sync_providers.dart';
import 'core/theme/app_theme.dart';
import 'features/auth/domain/auth_state.dart';
import 'features/auth/presentation/auth_controller.dart';
import 'features/media/presentation/downloads_controller.dart';
import 'l10n/generated/app_localizations.dart';

class YekkolaApp extends ConsumerStatefulWidget {
  const YekkolaApp({super.key});

  @override
  ConsumerState<YekkolaApp> createState() => _YekkolaAppState();
}

class _YekkolaAppState extends ConsumerState<YekkolaApp> {
  @override
  void initState() {
    super.initState();
    // Picks up a suspension, a name change or a revoked token; offline, the cached user stays.
    Future.microtask(() => ref.read(authControllerProvider.notifier).refreshUser());
  }

  @override
  Widget build(BuildContext context) {
    // The API ended this device's session (removed, phone changed, banned): its downloads go now — the
    // "next sync" of PRD-07 BR-04. A plain sign-out keeps them for that account only.
    ref.listen(authControllerProvider, (_, next) {
      if (next is SignedOut && next.sessionExpired) unawaited(ref.read(downloadStoreProvider).deleteAll());
    });
    // Starts the sync engine (outbox → API) for the app's lifetime.
    ref.watch(syncSchedulerProvider);
    final router = ref.watch(routerProvider);
    final config = ref.watch(appConfigProvider);

    return MaterialApp.router(
      onGenerateTitle: (context) => AppLocalizations.of(context).appTitle,
      debugShowCheckedModeBanner: !config.isProduction,
      theme: AppTheme.light(),
      darkTheme: AppTheme.dark(),
      locale: ref.watch(localeControllerProvider),
      supportedLocales: AppLocalizations.supportedLocales,
      localizationsDelegates: AppLocalizations.localizationsDelegates,
      routerConfig: router,
    );
  }
}
