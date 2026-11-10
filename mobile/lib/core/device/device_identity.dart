import 'dart:io' show Platform;

import 'package:device_info_plus/device_info_plus.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:uuid/uuid.dart';

import '../storage/secure_store.dart';

/// Who this app install is, as the API sees it (PRD-01 FR-06): a random install ID generated once
/// and kept in secure storage (sent as `X-Device-Id`), plus the platform, model and app version.
class DeviceIdentity {
  const DeviceIdentity({required this.installId, required this.platform, required this.name, required this.appVersion});

  static const installIdKey = 'install_id';

  final String installId;

  /// `android` or `ios` (the API's DevicePlatform values).
  final String platform;
  final String? name;
  final String appVersion;

  static Future<DeviceIdentity> load(SecureStore store) async {
    var installId = await store.read(installIdKey);
    if (installId == null || installId.isEmpty) {
      installId = const Uuid().v4();
      await store.write(installIdKey, installId);
    }

    final info = DeviceInfoPlugin();
    String? name;
    try {
      if (Platform.isAndroid) {
        final android = await info.androidInfo;
        name = '${android.manufacturer} ${android.model}'.trim();
      } else if (Platform.isIOS) {
        final ios = await info.iosInfo;
        name = ios.utsname.machine;
      }
    } catch (_) {
      // The device name is a label for the device list; sign-in works without it.
    }

    final package = await PackageInfo.fromPlatform();

    return DeviceIdentity(
      installId: installId,
      platform: Platform.isIOS ? 'ios' : 'android',
      name: name,
      appVersion: package.version,
    );
  }
}
