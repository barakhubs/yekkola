import 'dart:io' show Platform;

import 'package:flutter/services.dart';

/// Android `FLAG_SECURE` on player screens (PRD-07 FR-13): no screenshots, recordings or recent-apps
/// thumbnail while a lesson plays. iOS: capture hiding is a later step (FR-13 is a Should).
abstract final class SecureScreen {
  static const _channel = MethodChannel('com.yekkola.app/secure_screen');

  static Future<void> enable() => _set(true);

  static Future<void> disable() => _set(false);

  static Future<void> _set(bool secure) async {
    if (!Platform.isAndroid) return;
    try {
      await _channel.invokeMethod<void>('setSecure', secure);
    } on PlatformException {
      // Never block playback because the flag couldn't be set.
    }
  }
}
