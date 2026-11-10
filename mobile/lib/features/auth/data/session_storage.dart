import 'dart:convert';

import '../../../core/storage/secure_store.dart';
import '../domain/app_user.dart';
import '../domain/auth_state.dart';

/// Persists the session (device token + cached user) in secure storage.
class SessionStorage {
  const SessionStorage(this._store);

  static const _tokenKey = 'auth_token';
  static const _userKey = 'auth_user';

  final SecureStore _store;

  Future<AuthState> load() async {
    final token = await _store.read(_tokenKey);
    final userJson = await _store.read(_userKey);
    if (token == null || token.isEmpty || userJson == null) {
      return const SignedOut();
    }
    try {
      final user = AppUser.fromJson((jsonDecode(userJson) as Map).cast<String, Object?>());
      return SignedIn(user: user, token: token);
    } catch (_) {
      await clear();
      return const SignedOut();
    }
  }

  Future<void> save(SignedIn session) async {
    await _store.write(_tokenKey, session.token);
    await _store.write(_userKey, jsonEncode(session.user.toJson()));
  }

  Future<void> saveUser(AppUser user) => _store.write(_userKey, jsonEncode(user.toJson()));

  Future<void> clear() async {
    await _store.delete(_tokenKey);
    await _store.delete(_userKey);
  }
}
