import 'package:flutter/material.dart';

import '../../../core/i18n/formatters.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../auth/domain/device_summary.dart';

class DeviceTile extends StatelessWidget {
  const DeviceTile({super.key, required this.device, this.trailing});

  final DeviceSummary device;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final locale = Localizations.localeOf(context).languageCode;
    final name = device.name?.trim().isNotEmpty == true
        ? device.name!
        : (device.platform == 'ios' ? l10n.deviceUnnamedIos : l10n.deviceUnnamedAndroid);
    final lastActive = device.lastActiveAt;

    return ListTile(
      leading: Icon(device.platform == 'ios' ? Icons.phone_iphone : Icons.phone_android),
      title: Text(name),
      subtitle: Text(
        [
          if (device.isCurrent) l10n.deviceThisOne,
          if (lastActive != null) l10n.deviceLastActive(Formatters.dateTime(lastActive, locale)),
        ].join(' · '),
      ),
      trailing: trailing,
    );
  }
}
