import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Small key/value store for secrets (tokens, install ID). Backed by the Android Keystore /
/// iOS Keychain; tests use [MemorySecureStore].
abstract interface class SecureStore {
  Future<String?> read(String key);
  Future<void> write(String key, String value);
  Future<void> delete(String key);
}

class FlutterSecureStore implements SecureStore {
  const FlutterSecureStore([this._storage = const FlutterSecureStorage(iOptions: _ios)]);

  // "This device only": never in iCloud/Finder backups or Quick Start transfers, so the install ID
  // and token can't reappear on a second iPhone (Android: backups are off in the manifest).
  // "First unlock" lets background downloads read the token while the phone is locked.
  static const _ios = IOSOptions(accessibility: KeychainAccessibility.first_unlock_this_device);

  final FlutterSecureStorage _storage;

  @override
  Future<String?> read(String key) => _storage.read(key: key);

  @override
  Future<void> write(String key, String value) => _storage.write(key: key, value: value);

  @override
  Future<void> delete(String key) => _storage.delete(key: key);
}

class MemorySecureStore implements SecureStore {
  MemorySecureStore([Map<String, String>? values]) : values = values ?? {};

  final Map<String, String> values;

  @override
  Future<String?> read(String key) async => values[key];

  @override
  Future<void> write(String key, String value) async => values[key] = value;

  @override
  Future<void> delete(String key) async => values.remove(key);
}
