import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'core/i18n/locale_controller.dart';
import 'core/providers.dart';
import 'core/router/app_router.dart';
import 'core/theme/app_theme.dart';
import 'features/auth/presentation/auth_controller.dart';
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
