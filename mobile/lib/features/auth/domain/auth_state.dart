import 'app_user.dart';

/// Whether this install has a session. The token lives in secure storage; the user is cached
/// next to it so the app opens offline.
sealed class AuthState {
  const AuthState();

  String? get token => null;
}

final class SignedOut extends AuthState {
  const SignedOut({this.sessionExpired = false});

  /// True when the API ended the session (device removed elsewhere, phone changed, banned),
  /// so the sign-in screen can say why.
  final bool sessionExpired;
}

final class SignedIn extends AuthState {
  const SignedIn({required this.user, required this.token});

  final AppUser user;

  @override
  final String token;
}
