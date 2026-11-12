import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/i18n/locale_controller.dart';
import '../../../core/router/app_router.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../auth/presentation/auth_controller.dart';

/// The account menu in the tabs' app bars: downloads, devices, language, sign-out.
class AccountMenu extends ConsumerWidget {
  const AccountMenu({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context);
    final language = ref.watch(localeControllerProvider).languageCode;

    return PopupMenuButton<String>(
      icon: const Icon(Icons.account_circle_outlined),
      onSelected: (value) => switch (value) {
        'devices' => context.push(Routes.devices),
        'downloads' => context.push(Routes.downloads),
        'fr' || 'en' => ref.read(authControllerProvider.notifier).changeLanguage(value),
        _ => ref.read(authControllerProvider.notifier).signOut(),
      },
      itemBuilder: (_) => [
        PopupMenuItem(value: 'devices', child: Text(l10n.accountDevices)),
        PopupMenuItem(value: 'downloads', child: Text(l10n.downloadsTitle)),
        const PopupMenuDivider(),
        CheckedPopupMenuItem(value: 'fr', checked: language == 'fr', child: Text(l10n.languageFrench)),
        CheckedPopupMenuItem(value: 'en', checked: language == 'en', child: Text(l10n.languageEnglish)),
        const PopupMenuDivider(),
        PopupMenuItem(value: 'sign-out', child: Text(l10n.accountSignOut)),
      ],
    );
  }
}
