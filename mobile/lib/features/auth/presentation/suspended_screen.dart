import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../l10n/generated/app_localizations.dart';
import 'auth_controller.dart';
import 'widgets/auth_scaffold.dart';

/// Suspended accounts can sign in but only see this explanation (PRD-01 FR-15).
class SuspendedScreen extends ConsumerWidget {
  const SuspendedScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context);

    return AuthScaffold(
      title: l10n.suspendedTitle,
      subtitle: l10n.suspendedBody,
      children: [
        OutlinedButton(
          onPressed: () => ref.read(authControllerProvider.notifier).refreshUser(),
          child: Text(l10n.commonRetry),
        ),
        const SizedBox(height: 8),
        TextButton(
          onPressed: () => ref.read(authControllerProvider.notifier).signOut(),
          child: Text(l10n.accountSignOut),
        ),
      ],
    );
  }
}
