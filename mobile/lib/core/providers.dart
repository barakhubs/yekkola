import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../features/auth/presentation/auth_controller.dart';
import 'api/api_headers_interceptor.dart';
import 'api/generated/yekkola_api.dart';
import 'config/app_config.dart';
import 'device/device_identity.dart';
import 'i18n/locale_controller.dart';
import 'storage/secure_store.dart';

// Loaded once in main() before the first frame, then overridden in the ProviderScope.
final appConfigProvider = Provider<AppConfig>((ref) => throw UnimplementedError('overridden in main'));
final secureStoreProvider = Provider<SecureStore>((ref) => const FlutterSecureStore());
final deviceIdentityProvider = Provider<DeviceIdentity>((ref) => throw UnimplementedError('overridden in main'));

final dioProvider = Provider<Dio>((ref) {
  final config = ref.watch(appConfigProvider);
  final dio = Dio(
    BaseOptions(
      baseUrl: config.apiBaseUrl,
      connectTimeout: const Duration(seconds: 20),
      receiveTimeout: const Duration(seconds: 30),
      sendTimeout: const Duration(seconds: 30),
    ),
  );
  dio.interceptors.add(
    ApiHeadersInterceptor(
      installId: ref.watch(deviceIdentityProvider).installId,
      // Read lazily on each request: the session changes after the client is built.
      token: () => ref.read(authControllerProvider).token,
      locale: () => ref.read(localeControllerProvider).languageCode,
      onUnauthorized: () => ref.read(authControllerProvider.notifier).sessionEnded(),
      onSuspended: () => ref.read(authControllerProvider.notifier).refreshUser(),
    ),
  );
  ref.onDispose(dio.close);
  return dio;
});

final yekkolaApiProvider = Provider<YekkolaApi>((ref) => YekkolaApi(ref.watch(dioProvider)));
