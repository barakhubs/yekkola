import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/i18n/locale_controller.dart';
import '../../../core/router/app_router.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../auth/domain/auth_state.dart';
import '../../auth/presentation/auth_controller.dart';

/// Signed-in landing screen. My courses, downloads and the catalogue replace the placeholder in
/// later Phase 3 work.
class HomeScreen extends ConsumerWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context);
    final auth = ref.watch(authControllerProvider);
    final name = auth is SignedIn ? auth.user.name ?? '' : '';
    final theme = Theme.of(context);
    final language = ref.watch(localeControllerProvider).languageCode;

    return Scaffold(
      appBar: AppBar(
        title: Text(l10n.homeTitle),
        actions: [
          PopupMenuButton<String>(
            icon: const Icon(Icons.account_circle_outlined),
            onSelected: (value) => switch (value) {
              'devices' => context.push(Routes.devices),
              'fr' || 'en' => ref.read(authControllerProvider.notifier).changeLanguage(value),
              _ => ref.read(authControllerProvider.notifier).signOut(),
            },
            itemBuilder: (_) => [
              PopupMenuItem(value: 'devices', child: Text(l10n.accountDevices)),
              const PopupMenuDivider(),
              CheckedPopupMenuItem(value: 'fr', checked: language == 'fr', child: Text(l10n.languageFrench)),
              CheckedPopupMenuItem(value: 'en', checked: language == 'en', child: Text(l10n.languageEnglish)),
              const PopupMenuDivider(),
              PopupMenuItem(value: 'sign-out', child: Text(l10n.accountSignOut)),
            ],
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () => ref.read(authControllerProvider.notifier).refreshUser(),
        child: ListView(
          padding: const EdgeInsets.all(24),
          children: [
            Text(l10n.homeWelcome(name), style: theme.textTheme.headlineMedium),
            const SizedBox(height: 8),
            Text(l10n.homeComingSoon, style: theme.textTheme.bodyLarge),
          ],
        ),
      ),
    );
  }
}
