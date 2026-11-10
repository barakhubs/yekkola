import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/i18n/locale_controller.dart';
import '../../../core/providers.dart';
import '../data/auth_repository.dart';
import '../data/session_storage.dart';
import '../domain/app_user.dart';
import '../domain/auth_state.dart';

final sessionStorageProvider = Provider<SessionStorage>((ref) => SessionStorage(ref.watch(secureStoreProvider)));

final authRepositoryProvider = Provider<AuthRepository>(
  (ref) => AuthRepository(ref.watch(yekkolaApiProvider), ref.watch(deviceIdentityProvider)),
);

/// The session read from secure storage in main() before the first frame.
final initialAuthStateProvider = Provider<AuthState>((ref) => const SignedOut());

final authControllerProvider = NotifierProvider<AuthController, AuthState>(AuthController.new);

/// Owns the session: starts it after sign-in, keeps the cached user fresh, ends it.
class AuthController extends Notifier<AuthState> {
  @override
  AuthState build() => ref.watch(initialAuthStateProvider);

  SessionStorage get _storage => ref.read(sessionStorageProvider);

  Future<void> signedIn(SignInResult result) async {
    final session = SignedIn(user: result.user, token: result.token);
    await _storage.save(session);
    state = session;
    // The language chosen on this phone wins: the account follows it (best effort).
    final language = ref.read(localeControllerProvider).languageCode;
    if (result.user.locale != language && !result.user.needsProfile) {
      unawaited(_saveLanguage(language));
    }
  }

  /// Changes the UI language and, when signed in, the account's language (SMS, emails, receipts).
  Future<void> changeLanguage(String languageCode) async {
    await ref.read(localeControllerProvider.notifier).setLanguage(languageCode);
    await _saveLanguage(ref.read(localeControllerProvider).languageCode);
  }

  Future<void> _saveLanguage(String languageCode) async {
    final current = state;
    if (current is! SignedIn || current.user.needsProfile || current.user.locale == languageCode) return;
    try {
      final user = await ref.read(authRepositoryProvider).updateProfile(name: current.user.name!, locale: languageCode);
      await _userUpdatedFor(current.token, user);
    } on ApiException {
      // Offline: the phone keeps the new language; the next change or sign-in retries.
    }
  }

  Future<void> userUpdated(AppUser user) async {
    final current = state;
    if (current is SignedIn) await _userUpdatedFor(current.token, user);
  }

  /// Stores [user] only if the session that asked for it is still the current one: a slow answer
  /// must not write account A's user next to account B's token.
  Future<void> _userUpdatedFor(String token, AppUser user) async {
    final current = state;
    if (current is! SignedIn || current.token != token) return;
    await _storage.saveUser(user);
    state = SignedIn(user: user, token: token);
  }

  /// Refreshes the cached user (status, name). Offline, the cached copy stays in use.
  Future<void> refreshUser() async {
    final current = state;
    if (current is! SignedIn) return;
    try {
      await _userUpdatedFor(current.token, await ref.read(authRepositoryProvider).fetchMe());
    } on ApiException {
      // Offline or a server hiccup: keep the cached user. A 401/ban already ended the session.
    }
  }

  /// Signs this device out at once; the API is told in the background. The device stays
  /// registered. Offline, the token is still forgotten here; it stays valid on the API until this
  /// device is removed from the device list (or signs in again, which replaces it).
  Future<void> signOut() async {
    final current = state;
    if (current is! SignedIn) return;
    state = const SignedOut();
    await _storage.clear();
    unawaited(ref.read(authRepositoryProvider).logout(current.token).catchError((Object _) {}));
  }

  /// The API rejected our token (device removed elsewhere, phone changed, account banned).
  Future<void> sessionEnded() async {
    if (state is! SignedIn) return;
    state = const SignedOut(sessionExpired: true);
    await _storage.clear();
  }
}
