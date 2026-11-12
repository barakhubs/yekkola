import 'dart:convert';
import 'dart:math';
import 'dart:typed_data';

import 'package:cryptography/cryptography.dart';

import '../../../core/storage/secure_store.dart';

/// A media key wrapped for this device: AES-256-GCM under a per-device key (PRD-07 FR-07, FR-16).
/// The bound data (license, lesson, media, expiry) is authenticated, so editing the stored expiry or
/// moving the blob to another download makes unwrapping fail.
class WrappedKey {
  const WrappedKey({required this.nonce, required this.cipherText, required this.mac});

  factory WrappedKey.fromJson(Map<String, Object?> json) => WrappedKey(
    nonce: base64Decode(json['nonce']! as String),
    cipherText: base64Decode(json['cipher']! as String),
    mac: base64Decode(json['mac']! as String),
  );

  final List<int> nonce;
  final List<int> cipherText;
  final List<int> mac;

  Map<String, Object?> toJson() => {
    'nonce': base64Encode(nonce),
    'cipher': base64Encode(cipherText),
    'mac': base64Encode(mac),
  };
}

class KeyUnwrapFailed implements Exception {
  const KeyUnwrapFailed();
}

/// Keeps media keys on disk only wrapped. The wrapping key is 32 random bytes generated once per install and
/// held in secure storage (Android Keystore / iOS Keychain, "this device only"): it never leaves the phone and
/// is not backed up, so a copied download folder is useless elsewhere.
class OfflineKeyVault {
  OfflineKeyVault(this._store, {Random? random}) : _random = random ?? Random.secure();

  static const _wrapKeyName = 'media_wrap_key';
  static final _aes = AesGcm.with256bits();

  final SecureStore _store;
  final Random _random;
  // The future itself is shared: two first downloads at once must not create two different wrapping keys.
  Future<SecretKey>? _wrapKeyFuture;

  Future<WrappedKey> wrap(List<int> mediaKey, {required String boundTo}) async {
    final box = await _aes.encrypt(
      mediaKey,
      secretKey: await _wrapKey(),
      nonce: List<int>.generate(12, (_) => _random.nextInt(256)),
      aad: utf8.encode(boundTo),
    );
    return WrappedKey(nonce: box.nonce, cipherText: box.cipherText, mac: box.mac.bytes);
  }

  /// The plain 16-byte key, for the player only, held in memory while it plays.
  Future<Uint8List> unwrap(WrappedKey wrapped, {required String boundTo}) async {
    try {
      final plain = await _aes.decrypt(
        SecretBox(wrapped.cipherText, nonce: wrapped.nonce, mac: Mac(wrapped.mac)),
        secretKey: await _wrapKey(),
        aad: utf8.encode(boundTo),
      );
      return Uint8List.fromList(plain);
    } on SecretBoxAuthenticationError {
      throw const KeyUnwrapFailed();
    }
  }

  Future<SecretKey> _wrapKey() => _wrapKeyFuture ??= _loadOrCreate();

  Future<SecretKey> _loadOrCreate() async {
    try {
      var encoded = await _store.read(_wrapKeyName);
      if (encoded == null) {
        encoded = base64Encode(List<int>.generate(32, (_) => _random.nextInt(256)));
        await _store.write(_wrapKeyName, encoded);
      }
      return SecretKey(base64Decode(encoded));
    } catch (_) {
      _wrapKeyFuture = null; // let a later call retry (e.g. Keystore busy)
      rethrow;
    }
  }
}
