import '../../../core/api/generated/yekkola_api.dart';

/// What the order screen shows (PRD-05 FR-06–FR-09): no payment asked yet, waiting for the operator, or a
/// result. Read from the order and its latest payment as the API reports them — success is never assumed.
/// Mirrors apps/web's order-state.ts.
enum CheckoutState { choose, waiting, paid, failed, expired }

CheckoutState stateOf(OrderResource order) {
  switch (order.status) {
    case 'paid' || 'refunded' || 'partially_refunded':
      return CheckoutState.paid;
    case 'expired' || 'cancelled' || 'reversed':
      return CheckoutState.expired;
  }
  final latest = order.payments.isEmpty ? null : order.payments.first.status;
  return switch (latest) {
    null => CheckoutState.choose,
    'failed' || 'expired' => CheckoutState.failed,
    _ => CheckoutState.waiting,
  };
}

/// An order the student can still pay: open and before its payment deadline.
bool canStillPay(OrderResource order, DateTime now) {
  final deadline = DateTime.tryParse(order.expiresAt);
  return (order.status == 'pending' || order.status == 'awaiting_payment') && deadline != null && deadline.isAfter(now);
}

/// Polling while the operator asks the payer (FR-06): quick at first, then gentler.
const pollBackoff = [
  Duration(seconds: 2),
  Duration(seconds: 2),
  Duration(seconds: 3),
  Duration(seconds: 3),
  Duration(seconds: 5),
  Duration(seconds: 5),
  Duration(seconds: 8),
];

Duration pollDelay(int polls) => pollBackoff[polls.clamp(0, pollBackoff.length - 1)];
