import '../../../core/api/generated/yekkola_api.dart';

/// What the order screen shows (PRD-05 FR-06–FR-09): no payment asked yet, waiting for the operator, or a
/// result. Read from the order and its latest payment as the API reports them — success is never assumed.
/// Mirrors apps/web's order-state.ts.
enum CheckoutState { choose, waiting, paid, refunded, failed, expired }

CheckoutState stateOf(OrderResource order) {
  switch (order.status) {
    case 'paid' || 'partially_refunded':
      return CheckoutState.paid;
    case 'refunded':
      // Access was taken back with the refund: never "you now have access".
      return CheckoutState.refunded;
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
