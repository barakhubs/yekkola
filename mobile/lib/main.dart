import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'app.dart';
import 'core/config/app_config.dart';
import 'core/device/device_identity.dart';
import 'core/i18n/locale_controller.dart';
import 'core/providers.dart';
import 'core/storage/secure_store.dart';
import 'features/auth/data/session_storage.dart';
import 'features/auth/presentation/auth_controller.dart';

/// Reads what the first frame needs (install ID, session, language) from secure storage, so the
/// app opens straight onto the right screen — offline too.
Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  const store = FlutterSecureStore();
  final config = AppConfig.fromEnvironment();
  final (device, session, language) = await (
    DeviceIdentity.load(store),
    const SessionStorage(store).load(),
    store.read(LocaleController.storageKey),
  ).wait;

  runApp(
    ProviderScope(
      overrides: [
        appConfigProvider.overrideWithValue(config),
        secureStoreProvider.overrideWithValue(store),
        deviceIdentityProvider.overrideWithValue(device),
        initialAuthStateProvider.overrideWithValue(session),
        initialLocaleProvider.overrideWithValue(LocaleController.resolve(language)),
      ],
      child: const YekkolaApp(),
    ),
  );
}
