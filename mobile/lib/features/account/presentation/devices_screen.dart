import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_exception.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../auth/domain/device_summary.dart';
import '../../auth/presentation/api_error_text.dart';
import '../../auth/presentation/auth_controller.dart';
import 'device_tile.dart';

final devicesProvider = FutureProvider.autoDispose<List<DeviceSummary>>(
  (ref) => ref.watch(authRepositoryProvider).devices(),
  retry: (_, _) => null,
);

/// Registered devices and removal (PRD-01 FR-07). Removing this device signs it out here too.
class DevicesScreen extends ConsumerWidget {
  const DevicesScreen({super.key});

  Future<void> _remove(BuildContext context, WidgetRef ref, DeviceSummary device) async {
    final l10n = AppLocalizations.of(context);
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(l10n.accountRemoveDeviceTitle),
        content: Text(l10n.accountRemoveDeviceBody),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: Text(l10n.commonCancel)),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: Text(l10n.accountRemoveDevice)),
        ],
      ),
    );
    if (confirmed != true) return;

    try {
      await ref.read(authRepositoryProvider).removeDevice(device.id);
      if (device.isCurrent) {
        await ref.read(authControllerProvider.notifier).sessionEnded();
      } else {
        ref.invalidate(devicesProvider);
      }
    } on ApiException catch (e) {
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(apiErrorText(l10n, e))));
      }
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context);
    final devices = ref.watch(devicesProvider);

    return Scaffold(
      appBar: AppBar(title: Text(l10n.accountDevices)),
      body: RefreshIndicator(
        onRefresh: () => ref.refresh(devicesProvider.future),
        child: ListView(
          padding: const EdgeInsets.symmetric(vertical: 16),
          children: [
            Padding(padding: const EdgeInsets.symmetric(horizontal: 16), child: Text(l10n.accountDevicesIntro)),
            const SizedBox(height: 8),
            ...devices.when(
              loading: () => [
                const Padding(
                  padding: EdgeInsets.all(32),
                  child: Center(child: CircularProgressIndicator()),
                ),
              ],
              error: (error, _) => [
                Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    children: [
                      Text(error is ApiException ? apiErrorText(l10n, error) : l10n.errorGeneric),
                      const SizedBox(height: 8),
                      OutlinedButton(onPressed: () => ref.invalidate(devicesProvider), child: Text(l10n.commonRetry)),
                    ],
                  ),
                ),
              ],
              data: (list) => list.isEmpty
                  ? [Padding(padding: const EdgeInsets.all(16), child: Text(l10n.accountNoDevices))]
                  : [
                      for (final device in list)
                        DeviceTile(
                          device: device,
                          trailing: TextButton(
                            onPressed: () => _remove(context, ref, device),
                            child: Text(l10n.accountRemoveDevice),
                          ),
                        ),
                    ],
            ),
          ],
        ),
      ),
    );
  }
}
