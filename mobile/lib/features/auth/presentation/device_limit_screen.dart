import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../l10n/generated/app_localizations.dart';
import '../../account/presentation/device_tile.dart';
import '../domain/device_summary.dart';
import 'api_error_text.dart';
import 'sign_in_controller.dart';
import 'widgets/auth_scaffold.dart';

/// Step 3, only at the device limit (PRD-01 FR-08, PRD-07): pick a device to sign out; the same
/// code is sent again with `replace_device_id`.
class DeviceLimitScreen extends ConsumerWidget {
  const DeviceLimitScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context);
    final flow = ref.watch(signInControllerProvider);
    final limit = flow.deviceLimit;

    void back() {
      ref.read(signInControllerProvider.notifier).cancelDeviceChoice();
      context.pop();
    }

    return AuthScaffold(
      title: l10n.deviceLimitTitle,
      subtitle: limit == null ? null : l10n.deviceLimitBody(limit.limit),
      showBack: true,
      onBack: back,
      children: [
        if (flow.error != null) FormError(apiErrorText(l10n, flow.error!)),
        for (final device in limit?.devices ?? const <DeviceSummary>[])
          Card(
            child: Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  DeviceTile(device: device),
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 16),
                    child: OutlinedButton(
                      onPressed: flow.busy
                          ? null
                          : () async {
                              final outcome = await ref
                                  .read(signInControllerProvider.notifier)
                                  .replaceDevice(device.id);
                              // The code screen shows why and offers a new code.
                              if (outcome == VerifyOutcome.codeUnusable && context.mounted) context.pop();
                            },
                      child: Text(l10n.deviceLimitSignOut),
                    ),
                  ),
                ],
              ),
            ),
          ),
        if (flow.busy)
          const Padding(
            padding: EdgeInsets.all(16),
            child: Center(child: CircularProgressIndicator()),
          ),
        TextButton(onPressed: flow.busy ? null : back, child: Text(l10n.commonCancel)),
      ],
    );
  }
}
