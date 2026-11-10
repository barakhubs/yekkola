import 'package:dio/dio.dart' show Options;

import '../../../core/api/api_exception.dart';
import '../../../core/api/generated/yekkola_api.dart';
import '../../../core/device/device_identity.dart';
import '../domain/app_user.dart';
import '../domain/device_summary.dart';

/// Thrown by [AuthRepository.verifyOtp] when this account already has the maximum number of
/// registered devices: the user picks one to sign out and verifies again with the same code.
class DeviceLimitReached implements Exception {
  const DeviceLimitReached({required this.limit, required this.devices});

  final int limit;
  final List<DeviceSummary> devices;
}

class OtpChallenge {
  const OtpChallenge({required this.expiresAt, required this.resendAfter});

  final DateTime expiresAt;
  final Duration resendAfter;
}

class SignInResult {
  const SignInResult({required this.user, required this.token, required this.isNewUser});

  final AppUser user;
  final String token;
  final bool isNewUser;
}

/// Sign-in and account calls (PRD-01). Every failure is an [ApiException] (or [DeviceLimitReached]).
class AuthRepository {
  AuthRepository(this._api, this._device);

  final YekkolaApi _api;
  final DeviceIdentity _device;

  /// The API normalises the number to E.164 (DRC is the default region) and answers the same way
  /// whether or not the number has an account.
  Future<OtpChallenge> requestOtp(String phone) async {
    final response = await ApiException.guard(() => _api.otp.authOtpRequest(body: RequestOtpRequest(phone: phone)));
    return OtpChallenge(
      expiresAt: DateTime.parse(response.data.expiresAt).toLocal(),
      resendAfter: Duration(seconds: response.data.resendAfterSeconds),
    );
  }

  Future<SignInResult> verifyOtp({required String phone, required String code, String? replaceDeviceId}) async {
    try {
      final response = await ApiException.guard(
        () => _api.otp.authOtpVerify(
          body: VerifyOtpRequest(
            phone: phone,
            code: code,
            replaceDeviceId: replaceDeviceId,
            device: VerifyOtpRequestDevice(
              installId: _device.installId,
              platform: DevicePlatform.fromJson(_device.platform),
              name: _device.name,
              appVersion: _device.appVersion,
            ),
          ),
        ),
      );
      final token = response.data.token;
      if (token == null || token.isEmpty) {
        // A mobile sign-in always gets a device token; anything else is a server bug.
        throw const ApiException(code: 'auth.token_missing', status: 200);
      }
      return SignInResult(
        user: AppUser.fromJson(response.data.user.toJson()),
        token: token,
        isNewUser: response.data.isNewUser,
      );
    } on ApiException catch (e) {
      if (e.code == 'device.limit_reached') {
        final devices = (e.context['devices'] as List? ?? const [])
            .whereType<Map<Object?, Object?>>()
            .map((d) => DeviceSummary.fromJson(d.cast<String, Object?>()))
            .toList();
        throw DeviceLimitReached(limit: (e.context['limit'] as int?) ?? devices.length, devices: devices);
      }
      rethrow;
    }
  }

  Future<AppUser> fetchMe() async {
    final response = await ApiException.guard(() => _api.profile.meShow());
    return AppUser.fromJson(response.data.toJson());
  }

  /// Name and language (first sign-in, language changes).
  Future<AppUser> updateProfile({required String name, required String locale}) async {
    final response = await ApiException.guard(
      () => _api.profile.meUpdate(
        body: UpdateProfileRequest(name: name, locale: UpdateProfileRequestLocale.fromJson(locale)),
      ),
    );
    return AppUser.fromJson(response.data.toJson());
  }

  /// Revokes [token] (this device's). The device stays registered. The token is passed in because
  /// the session is already gone locally when this runs.
  Future<void> logout(String token) =>
      ApiException.guard(() => _api.logout.authLogout(options: Options(headers: {'Authorization': 'Bearer $token'})));

  Future<List<DeviceSummary>> devices() async {
    final response = await ApiException.guard(() => _api.device.meDevicesIndex());
    return response.data.map((d) => DeviceSummary.fromJson(d.toJson())).toList();
  }

  Future<void> removeDevice(String id) => ApiException.guard(() => _api.device.meDevicesDestroy(device: id));
}
