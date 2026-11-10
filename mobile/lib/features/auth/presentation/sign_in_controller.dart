import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_exception.dart';
import '../data/auth_repository.dart';
import 'auth_controller.dart';

/// The phone → code → (device limit) sign-in flow (PRD-01 FR-01..FR-03, FR-08).
class SignInFlow {
  const SignInFlow({this.phone = '', this.challenge, this.code, this.deviceLimit, this.busy = false, this.error});

  final String phone;
  final OtpChallenge? challenge;

  /// The verified-but-not-yet-accepted code, kept only while the user picks a device to replace:
  /// the API accepts the same code again with `replace_device_id`.
  final String? code;
  final DeviceLimitReached? deviceLimit;
  final bool busy;
  final ApiException? error;

  SignInFlow copyWith({
    String? phone,
    OtpChallenge? challenge,
    String? code,
    DeviceLimitReached? deviceLimit,
    bool? busy,
    ApiException? error,
    bool clearError = false,
    bool clearDeviceLimit = false,
  }) => SignInFlow(
    phone: phone ?? this.phone,
    challenge: challenge ?? this.challenge,
    code: clearDeviceLimit ? null : (code ?? this.code),
    deviceLimit: clearDeviceLimit ? null : (deviceLimit ?? this.deviceLimit),
    busy: busy ?? this.busy,
    error: clearError ? null : (error ?? this.error),
  );
}

enum VerifyOutcome {
  signedIn,
  newUser,
  deviceLimit,

  /// The code can't be used again (expired, too many tries): back to the code step for a new one.
  codeUnusable,
  failed,
}

class SignInController extends Notifier<SignInFlow> {
  @override
  SignInFlow build() => const SignInFlow();

  AuthRepository get _repository => ref.read(authRepositoryProvider);

  /// Sends a code. National numbers are fine: the API defaults to the DRC (+243).
  Future<bool> requestCode(String phone) async {
    final normalized = normalizePhoneInput(phone);
    state = SignInFlow(phone: normalized, busy: true);
    try {
      final challenge = await _repository.requestOtp(normalized);
      state = state.copyWith(challenge: challenge, busy: false);
      return true;
    } on ApiException catch (e) {
      state = state.copyWith(busy: false, error: e);
      return false;
    }
  }

  Future<bool> resend() => requestCode(state.phone);

  Future<VerifyOutcome> verify(String code) => _verify(code: code);

  /// Signs [deviceId] out to make room for this phone, reusing the code already entered.
  Future<VerifyOutcome> replaceDevice(String deviceId) {
    final code = state.code;
    if (code == null) return Future.value(VerifyOutcome.failed);
    return _verify(code: code, replaceDeviceId: deviceId);
  }

  void cancelDeviceChoice() => state = state.copyWith(clearDeviceLimit: true, clearError: true);

  Future<VerifyOutcome> _verify({required String code, String? replaceDeviceId}) async {
    state = state.copyWith(busy: true, clearError: true);
    try {
      final result = await _repository.verifyOtp(phone: state.phone, code: code, replaceDeviceId: replaceDeviceId);
      await ref.read(authControllerProvider.notifier).signedIn(result);
      state = const SignInFlow();
      return result.isNewUser || result.user.needsProfile ? VerifyOutcome.newUser : VerifyOutcome.signedIn;
    } on DeviceLimitReached catch (limit) {
      state = state.copyWith(busy: false, code: code, deviceLimit: limit);
      return VerifyOutcome.deviceLimit;
    } on ApiException catch (e) {
      if (replaceDeviceId != null && _unusableCodes.contains(e.code)) {
        state = state.copyWith(busy: false, error: e, clearDeviceLimit: true);
        return VerifyOutcome.codeUnusable;
      }
      state = state.copyWith(busy: false, error: e);
      return VerifyOutcome.failed;
    }
  }

  static const _unusableCodes = {'otp.expired', 'otp.too_many_attempts', 'otp.locked'};
}

/// Keeps a typed number as the user meant it: `+…` stays international; otherwise spaces and
/// dashes go and a leading trunk `0` is dropped before the DRC country code is added
/// (`0812 345 678` → `+243812345678`). The API does the real validation.
String normalizePhoneInput(String input) {
  final trimmed = input.trim();
  final digits = trimmed.replaceAll(RegExp(r'[^0-9]'), '');
  if (trimmed.startsWith('+')) return '+$digits';
  if (digits.startsWith('00')) return '+${digits.substring(2)}';
  if (digits.startsWith('243') && digits.length > 10) return '+$digits';
  return '+243${digits.startsWith('0') ? digits.substring(1) : digits}';
}

final signInControllerProvider = NotifierProvider<SignInController, SignInFlow>(SignInController.new);
