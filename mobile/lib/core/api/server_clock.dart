import 'dart:io' show HttpDate;

import 'package:dio/dio.dart';

/// The API's clock, learnt from each response's `Date` header. Low-end phones often have the wrong time;
/// countdowns to server deadlines (an order's payment timeout) use this instead of the phone's clock.
class ServerClock {
  Duration _offset = Duration.zero;

  /// Now, by the API's clock (the phone's clock until a response has been seen).
  DateTime now() => DateTime.now().toUtc().add(_offset);

  void observe(String? dateHeader) {
    if (dateHeader == null) return;
    try {
      _offset = HttpDate.parse(dateHeader).difference(DateTime.now().toUtc());
    } on FormatException {
      // Not a valid date: keep the last offset.
    }
  }

  Interceptor get interceptor => InterceptorsWrapper(
    onResponse: (response, handler) {
      observe(response.headers.value('date'));
      handler.next(response);
    },
  );
}
