import 'package:flutter/services.dart' show appFlavor;

enum AppFlavor { staging, production }

/// Build-time configuration. The flavor comes from `flutter run --flavor <name>` (Android) and
/// picks the API; `--dart-define=API_BASE_URL=...` overrides it (e.g. a local API from the emulator:
/// `http://10.0.2.2:8000/api/v1`).
class AppConfig {
  const AppConfig({required this.flavor, required this.apiBaseUrl});

  factory AppConfig.fromEnvironment() {
    final name = appFlavor ?? const String.fromEnvironment('FLAVOR', defaultValue: 'staging');
    final flavor = AppFlavor.values.firstWhere((f) => f.name == name, orElse: () => AppFlavor.staging);
    const override = String.fromEnvironment('API_BASE_URL');

    return AppConfig(flavor: flavor, apiBaseUrl: override.isNotEmpty ? override : _defaultApiBaseUrls[flavor]!);
  }

  // TODO(TODO.md Phase 3): confirm the production API host once the production environment exists.
  static const _defaultApiBaseUrls = {
    AppFlavor.staging: 'https://api.yekkola.com/api/v1',
    AppFlavor.production: 'https://api.yekkola.com/api/v1',
  };

  final AppFlavor flavor;
  final String apiBaseUrl;

  bool get isProduction => flavor == AppFlavor.production;
}
