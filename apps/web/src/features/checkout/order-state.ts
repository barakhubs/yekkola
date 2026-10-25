import type { OrderResource } from "@yekkola/api-client/model";

/** What the order page shows: no payment asked yet, waiting for the operator, or a result. */
export type CheckoutState = "choose" | "waiting" | "paid" | "failed" | "expired";

/** Reads the order and its latest payment as the API reports them — never assumes success. */
export function stateOf(order: Pick<OrderResource, "status" | "payments">): CheckoutState {
  if (order.status === "paid" || order.status === "refunded" || order.status === "partially_refunded")
    return "paid";
  if (order.status === "expired" || order.status === "cancelled" || order.status === "reversed")
    return "expired";
  const latest = order.payments[0]?.status;
  if (latest === undefined) return "choose";
  if (latest === "failed" || latest === "expired") return "failed";
  return "waiting";
}

/** An order the student can still pay: open and not past its payment deadline. */
export function canStillPay(order: Pick<OrderResource, "status" | "expires_at">, now: number): boolean {
  return (
    (order.status === "pending" || order.status === "awaiting_payment") &&
    new Date(order.expires_at).getTime() > now
  );
}
