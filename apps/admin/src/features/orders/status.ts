import type { StatusTone } from "@yekkola/ui/blocks/status-badge";

export type OrderState =
  | "pending"
  | "awaiting_payment"
  | "paid"
  | "expired"
  | "cancelled"
  | "reversed"
  | "refunded"
  | "partially_refunded";

export const ORDER_TONES: Record<OrderState, StatusTone> = {
  pending: "neutral",
  awaiting_payment: "warning",
  paid: "success",
  expired: "neutral",
  cancelled: "neutral",
  reversed: "danger",
  refunded: "info",
  partially_refunded: "info",
};

export const ORDER_STATES = Object.keys(ORDER_TONES) as OrderState[];

export function orderStateOf(status: string): OrderState {
  return status in ORDER_TONES ? (status as OrderState) : "pending";
}

export type PaymentState = "initiated" | "pending" | "succeeded" | "failed" | "expired" | "reversed";

export const PAYMENT_TONES: Record<PaymentState, StatusTone> = {
  initiated: "neutral",
  pending: "warning",
  succeeded: "success",
  failed: "danger",
  expired: "neutral",
  reversed: "danger",
};

export function paymentStateOf(status: string): PaymentState {
  return status in PAYMENT_TONES ? (status as PaymentState) : "pending";
}
