import 'package:dio/dio.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/api/generated/yekkola_api.dart';
import '../../../core/db/app_database.dart';
import 'idempotency.dart';

/// Enrolment and mobile-money checkout (PRD-05). Prices, exclusions, coupons and rails are decided by the API;
/// the app shows them and submits. Money writes carry an Idempotency-Key that survives app restarts.
class CheckoutRepository {
  CheckoutRepository(this._api, this._dio, this._db) : keys = IdempotencyKeys(_db);

  final YekkolaApi _api;
  final Dio _dio;
  final AppDatabase _db;
  final IdempotencyKeys keys;

  /// One tap for a free course (FR-01). Enrolling again changes nothing.
  Future<void> enrollFree(String courseId) =>
      ApiException.guard(() => _api.enrollment.coursesEnroll(course: courseId)).then((_) {});

  /// Server-side price (FR-02, FR-04): courses already owned are left out.
  Future<CheckoutQuoteResponse200Data> quote(String courseId, {String? coupon}) async {
    final response = await ApiException.guard(
      () => _dio.post<Map<String, Object?>>('/checkout/quote', data: _selection(courseId, coupon)),
    );
    return CheckoutQuoteResponse200.fromJson(response.data!).data;
  }

  /// Operators and whether each can take a payment now in [currency] (FR-19).
  Future<Map<String, bool>> rails(String currency) async {
    final response = await ApiException.guard(() => _api.checkout.checkoutRails(currency: Currency.fromJson(currency)));
    return response.data;
  }

  /// Creates the order (FR-03) under a key kept per account, course and coupon, so a retry after a lost
  /// answer returns the same order. Remembered as this course's open order, to resume after a restart.
  Future<OrderResource> createOrder({required String userId, required String courseId, String? coupon}) async {
    final scope = 'order:$userId:$courseId:${coupon ?? ''}';
    final order = await keys.run(scope, (key) async {
      final response = await ApiException.guard(
        () => _dio.post<Map<String, Object?>>(
          '/orders',
          data: _selection(courseId, coupon),
          options: Options(headers: {'Idempotency-Key': key}),
        ),
      );
      return OrdersStoreResponse201.fromJson(response.data!).data;
    });
    await rememberOpenOrder(userId, courseId, order.id);
    return order;
  }

  Future<OrderResource> order(String orderId) async =>
      (await ApiException.guard(() => _api.order.ordersShow(order: orderId))).data;

  /// Asks the operator to collect (FR-05). One key per known attempt count: a request lost on the network keeps
  /// its key until the API shows how it ended; once that attempt appears (and fails), the next try is new.
  Future<PaymentResource> pay(OrderResource order, {required String rail, String? msisdn}) => keys.run(
    'pay:${order.id}:${order.payments.length}',
    (key) async => (await ApiException.guard(
      () => _api.order.ordersPay(
        order: order.id,
        body: StartPaymentRequest(rail: MobileMoneyRail.fromJson(rail), msisdn: msisdn),
        options: Options(headers: {'Idempotency-Key': key}),
      ),
    )).data,
  );

  /// The open order of a course on this phone, if any (the waiting screen survives the app being closed).
  Future<String?> openOrder(String userId, String courseId) async => (await (_db.select(
    _db.syncState,
  )..where((s) => s.key.equals(_openKey(userId, courseId)))).getSingleOrNull())?.value;

  Future<void> rememberOpenOrder(String userId, String courseId, String orderId) => _db
      .into(_db.syncState)
      .insertOnConflictUpdate(SyncStateCompanion.insert(key: _openKey(userId, courseId), value: orderId));

  Future<void> forgetOpenOrder(String userId, String courseId) =>
      (_db.delete(_db.syncState)..where((s) => s.key.equals(_openKey(userId, courseId)))).go();

  static String _openKey(String userId, String courseId) => 'open_order:$userId:$courseId';

  // `course_ids` or `bundle_id`, never both (the API prohibits it; the generated model would send both).
  static Map<String, Object?> _selection(String courseId, String? coupon) => {
    'course_ids': [courseId],
    if (coupon != null && coupon.trim().isNotEmpty) 'coupon_code': coupon.trim(),
  };
}
