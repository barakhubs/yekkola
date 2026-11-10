import 'package:dio/dio.dart';

/// An API failure in the standard error envelope (docs/architecture.md §4.1):
/// `{ type, title, status, code, errors?, ...context }`. [code] is stable and machine-readable;
/// [title] is already translated by the API (Accept-Language).
class ApiException implements Exception {
  const ApiException({
    required this.code,
    required this.status,
    this.title,
    this.fieldErrors = const {},
    this.context = const {},
    this.retryAfter,
  });

  /// No response at all (offline, DNS, timeout).
  static const networkCode = 'network.unreachable';

  factory ApiException.fromDio(DioException e) {
    final response = e.response;
    if (response == null) {
      return const ApiException(code: networkCode, status: 0);
    }

    final body = response.data;
    final map = body is Map ? body.cast<String, Object?>() : const <String, Object?>{};
    final errors = <String, List<String>>{};
    final rawErrors = map['errors'];
    if (rawErrors is Map) {
      for (final entry in rawErrors.entries) {
        final messages = entry.value;
        errors['${entry.key}'] = messages is List ? messages.map((m) => '$m').toList() : ['$messages'];
      }
    }

    final retryHeader = int.tryParse(response.headers.value('retry-after') ?? '');
    final retryContext = map['retry_after'];

    return ApiException(
      code: map['code'] is String ? map['code'] as String : 'http.${response.statusCode ?? 0}',
      status: response.statusCode ?? 0,
      title: map['title'] is String ? map['title'] as String : null,
      fieldErrors: errors,
      context: map,
      retryAfter: retryContext is int ? retryContext : retryHeader,
    );
  }

  /// Runs [call] and turns Dio failures into [ApiException].
  static Future<T> guard<T>(Future<T> Function() call) async {
    try {
      return await call();
    } on DioException catch (e) {
      throw ApiException.fromDio(e);
    }
  }

  final String code;
  final int status;
  final String? title;
  final Map<String, List<String>> fieldErrors;

  /// The whole error body, including domain context (e.g. `devices` for `device.limit_reached`).
  final Map<String, Object?> context;

  /// Seconds to wait before retrying (rate limits, OTP cooldown).
  final int? retryAfter;

  bool get isNetwork => code == networkCode;

  String? fieldError(String field) => fieldErrors[field]?.firstOrNull;

  @override
  String toString() => 'ApiException($status $code)';
}
