import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../providers.dart';

/// UI language: French by default, English on request. Stored on the device and, once signed in,
/// taken from the account.
class LocaleController extends Notifier<Locale> {
  static const storageKey = 'locale';
  static const supported = [Locale('fr'), Locale('en')];
  static const fallback = Locale('fr');

  /// The language saved on this device, read in main() before the first frame.
  static Locale resolve(String? languageCode) =>
      supported.firstWhere((l) => l.languageCode == languageCode, orElse: () => fallback);

  @override
  Locale build() => ref.watch(initialLocaleProvider);

  Future<void> setLanguage(String languageCode) async {
    final locale = resolve(languageCode);
    if (locale == state) return;
    state = locale;
    await ref.read(secureStoreProvider).write(storageKey, locale.languageCode);
  }
}

final initialLocaleProvider = Provider<Locale>((ref) => LocaleController.fallback);

final localeControllerProvider = NotifierProvider<LocaleController, Locale>(LocaleController.new);
