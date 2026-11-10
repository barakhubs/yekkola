import 'package:dio/dio.dart';
import 'package:uuid/uuid.dart';

/// Adds what every API call needs: the bearer token, `X-Device-Id` (always, signed in or not:
/// it marks the request as the mobile app — PRD-01 FR-06/FR-10), the UI language for translated
/// errors, and a request ID for tracing.
///
/// Reports what the API says about the current token: a 401 (device removed, phone changed) or a
/// 403 `account.banned` (the API has deleted the token) ends the session; a 403
/// `account.suspended` means the account changed status mid-session (PRD-01 FR-15).
class ApiHeadersInterceptor extends Interceptor {
  ApiHeadersInterceptor({
    required this.installId,
    required this.token,
    required this.locale,
    required this.onUnauthorized,
    required this.onSuspended,
  });

  final String installId;
  final String? Function() token;
  final String Function() locale;
  final void Function() onUnauthorized;
  final void Function() onSuspended;

  static const _uuid = Uuid();

  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    options.headers['X-Device-Id'] = installId;
    options.headers['Accept-Language'] = locale();
    options.headers['X-Request-Id'] = _uuid.v4();
    final bearer = token();
    if (bearer != null && bearer.isNotEmpty) {
      options.headers['Authorization'] = 'Bearer $bearer';
    }
    handler.next(options);
  }

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) {
    // Only the current token's rejection counts: a late answer for a token that was already
    // replaced (e.g. by signing in again) must not touch the new session.
    final sent = err.requestOptions.headers['Authorization'];
    final current = token();
    if (sent != null && sent == 'Bearer $current') {
      final status = err.response?.statusCode;
      final body = err.response?.data;
      final code = body is Map ? body['code'] : null;
      if (status == 401 || (status == 403 && code == 'account.banned')) {
        onUnauthorized();
      } else if (status == 403 && code == 'account.suspended') {
        onSuspended();
      }
    }
    handler.next(err);
  }
}
