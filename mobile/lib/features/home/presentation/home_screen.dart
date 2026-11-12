import 'package:flutter/material.dart';

import '../../../l10n/generated/app_localizations.dart';

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
