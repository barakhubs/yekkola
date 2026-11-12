import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart' show Override;
import 'package:yekkola/core/config/app_config.dart';
import 'package:yekkola/core/device/device_identity.dart';
import 'package:yekkola/core/providers.dart';
import 'package:yekkola/core/storage/secure_store.dart';
import 'package:yekkola/features/auth/domain/auth_state.dart';
import 'package:yekkola/features/auth/presentation/auth_controller.dart';

class FakeResponse {
  const FakeResponse(this.status, [this.body, this.headers = const {}]);

  final int status;
  final Object? body;
  final Map<String, String> headers;
}

/// Answers Dio requests from a handler and records them, so tests exercise the real generated
/// client, interceptors and error parsing without a network.
class FakeApi implements HttpClientAdapter {
  FakeApi(this.handler);

  final FakeResponse Function(RequestOptions request, Map<String, Object?>? body) handler;
  final requests = <RequestOptions>[];
  final bodies = <Map<String, Object?>?>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    Map<String, Object?>? body;
    if (requestStream != null) {
      final bytes = await requestStream.expand((chunk) => chunk).toList();
      if (bytes.isNotEmpty) body = (jsonDecode(utf8.decode(bytes)) as Map).cast<String, Object?>();
    } else if (options.data is String && (options.data as String).isNotEmpty) {
      body = (jsonDecode(options.data as String) as Map).cast<String, Object?>();
    }
    requests.add(options);
    bodies.add(body);

    final response = handler(options, body);
    return ResponseBody.fromString(
      response.body == null ? '' : jsonEncode(response.body),
      response.status,
      headers: {
        Headers.contentTypeHeader: ['application/json'],
        for (final h in response.headers.entries) h.key: [h.value],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

const testDevice = DeviceIdentity(
  installId: 'install-1234',
  platform: 'android',
  name: 'Tecno Spark',
  appVersion: '0.1.0',
);

ProviderContainer makeContainer(
  FakeApi api, {
  AuthState initial = const SignedOut(),
  MemorySecureStore? store,
  List<Override> extra = const [],
}) {
  final container = ProviderContainer.test(
    overrides: [
      ...extra,
      appConfigProvider.overrideWithValue(
        const AppConfig(flavor: AppFlavor.staging, apiBaseUrl: 'https://api.test/api/v1'),
      ),
      secureStoreProvider.overrideWithValue(store ?? MemorySecureStore()),
      deviceIdentityProvider.overrideWithValue(testDevice),
      initialAuthStateProvider.overrideWithValue(initial),
    ],
  );
  container.read(dioProvider).httpClientAdapter = api;
  return container;
}

Map<String, Object?> userJson({String? name = 'Amani', String status = 'active', String locale = 'fr'}) => {
  'id': '01JUSER00000000000000000000',
  'phone': '+243812345678',
  'name': name,
  'email': null,
  'email_verified': false,
  'locale': locale,
  'province': null,
  'city': null,
  'status': status,
  'roles': ['student'],
  'is_staff': false,
  'has_password': false,
  'must_change_password': false,
  'impersonation': null,
  'permissions': <String>[],
  'deletion_requested_at': null,
  'deletion_scheduled_for': null,
  'created_at': '2026-11-01T10:00:00Z',
};
