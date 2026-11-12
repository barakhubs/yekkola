import 'package:dio/dio.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/api/generated/yekkola_api.dart';
import '../../../core/db/app_database.dart';
import 'idempotency.dart';

class OrderResult {
  const OrderResult(this.order, {this.keptOpenOrder = false});

  final OrderResource order;

  /// An order that already existed was reused (it keeps its price; a new coupon doesn't apply to it).
  final bool keptOpenOrder;
}

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

  /// The order to pay for this course (FR-03): the open one if there is one, else a new one.
  /// - An order remembered on this phone and still open (per the API) is reused; it keeps its own price, so a
  ///   coupon can't be added to it ([OrderResult.keptOpenOrder]).
  /// - The API refuses a second open order for the same course (`order.open_order_exists`, e.g. one opened
  ///   on the web or before a reinstall): that order is resumed.
  /// - A key that brings back an order which can no longer be paid (an old, lost attempt) is dropped and the
  ///   order created once more under a new key.
  Future<OrderResult> orderFor({required String userId, required String courseId, String? coupon}) async {
    final openId = await openOrder(userId, courseId);
    if (openId != null) {
      try {
        final open = await order(openId);
        if (canBePaid(open) || open.status == 'paid') return OrderResult(open, keptOpenOrder: true);
        await forgetOpenOrder(userId, courseId);
      } on ApiException catch (e) {
        if (e.status != 404) rethrow; // offline: keep it, try again later
        await forgetOpenOrder(userId, courseId);
      }
    }

    try {
      var created = await _create(userId, courseId, coupon);
      if (!canBePaid(created) && created.status != 'paid') {
        await keys.forget(_orderScope(userId, courseId, coupon));
        created = await _create(userId, courseId, coupon);
      }
      return OrderResult(created);
    } on ApiException catch (e) {
      final existing = e.context['order_id'];
      if (e.code == 'order.open_order_exists' && existing is String) {
        await rememberOpenOrder(userId, courseId, existing);
        return OrderResult(await order(existing), keptOpenOrder: true);
      }
      rethrow;
    }
  }

  /// Open per the API (the server expires orders past their deadline). The phone's clock is not trusted.
  static bool canBePaid(OrderResource order) => order.status == 'pending' || order.status == 'awaiting_payment';

  static String _orderScope(String userId, String courseId, String? coupon) =>
      'order:$userId:$courseId:${coupon ?? ''}';

  /// One order under a key kept per account, course and coupon: a retry after a lost answer returns the same
  /// order. It is remembered as this course's open order before the key is forgotten.
  Future<OrderResource> _create(String userId, String courseId, String? coupon) =>
      keys.run(_orderScope(userId, courseId, coupon), (key) async {
        final response = await ApiException.guard(
          () => _dio.post<Map<String, Object?>>(
            '/orders',
            data: _selection(courseId, coupon),
            options: Options(headers: {'Idempotency-Key': key}),
          ),
        );
        return OrdersStoreResponse201.fromJson(response.data!).data;
      }, onResult: (order) => rememberOpenOrder(userId, courseId, order.id));

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
