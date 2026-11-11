import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:path_provider/path_provider.dart';

import 'app.dart';
import 'core/config/app_config.dart';
import 'core/device/device_identity.dart';
import 'core/i18n/locale_controller.dart';
import 'core/providers.dart';
import 'core/storage/secure_store.dart';
import 'features/auth/data/session_storage.dart';
import 'features/auth/presentation/auth_controller.dart';
import 'features/media/presentation/downloads_controller.dart';

/// Reads what the first frame needs (install ID, session, language) from secure storage, so the
/// app opens straight onto the right screen — offline too.
Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  const store = FlutterSecureStore();
  final config = AppConfig.fromEnvironment();
  final (device, session, language, support) = await (
    DeviceIdentity.load(store),
    const SessionStorage(store).load(),
    store.read(LocaleController.storageKey),
    getApplicationSupportDirectory(),
  ).wait;

  runApp(
    ProviderScope(
      overrides: [
        appConfigProvider.overrideWithValue(config),
        secureStoreProvider.overrideWithValue(store),
        deviceIdentityProvider.overrideWithValue(device),
        initialAuthStateProvider.overrideWithValue(session),
        initialLocaleProvider.overrideWithValue(LocaleController.resolve(language)),
        // App-private, excluded from backups (AndroidManifest).
        downloadsRootProvider.overrideWithValue(Directory('${support.path}/downloads')),
      ],
      child: const YekkolaApp(),
    ),
  );
}
