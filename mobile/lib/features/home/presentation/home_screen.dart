import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../l10n/generated/app_localizations.dart';
import '../../auth/domain/auth_state.dart';
import '../../auth/presentation/auth_controller.dart';
import 'account_menu.dart';

/// The "My courses" tab. My courses, learning and downloads replace the placeholder in later Phase 3 work.
class HomeScreen extends ConsumerWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context);
    final auth = ref.watch(authControllerProvider);
    final name = auth is SignedIn ? auth.user.name ?? '' : '';
    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(title: Text(l10n.homeTitle), actions: const [AccountMenu()]),
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

/// Bottom navigation between the signed-in tabs.
class TabsShell extends StatelessWidget {
  const TabsShell({super.key, required this.selectedIndex, required this.onSelect, required this.child});

  final int selectedIndex;
  final void Function(int index) onSelect;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      body: child,
      bottomNavigationBar: NavigationBar(
        selectedIndex: selectedIndex,
        onDestinationSelected: onSelect,
        destinations: [
          NavigationDestination(
            icon: const Icon(Icons.explore_outlined),
            selectedIcon: const Icon(Icons.explore),
            label: l10n.navExplore,
          ),
          NavigationDestination(
            icon: const Icon(Icons.school_outlined),
            selectedIcon: const Icon(Icons.school),
            label: l10n.homeTitle,
          ),
        ],
      ),
    );
  }
}
