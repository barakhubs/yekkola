import type { StatusTone } from "@yekkola/ui/blocks/status-badge";

export type RefundState =
  "requested" | "approved" | "rejected" | "processing" | "completed" | "failed" | "cancelled";

export const REFUND_TONES: Record<RefundState, StatusTone> = {
  requested: "warning",
  approved: "info",
  rejected: "neutral",
  processing: "info",
  completed: "success",
  failed: "danger",
  cancelled: "neutral",
};

export const REFUND_STATES = Object.keys(REFUND_TONES) as RefundState[];

export function refundStateOf(status: string): RefundState {
  return status in REFUND_TONES ? (status as RefundState) : "requested";
}

export type BatchState = "draft" | "processing" | "completed" | "partially_failed";

export const BATCH_TONES: Record<BatchState, StatusTone> = {
  draft: "warning",
  processing: "info",
  completed: "success",
  partially_failed: "danger",
};

export const BATCH_STATES = Object.keys(BATCH_TONES) as BatchState[];

export function batchStateOf(status: string): BatchState {
  return status in BATCH_TONES ? (status as BatchState) : "draft";
}

export type PayoutState = "pending" | "excluded" | "processing" | "paid" | "failed";

export const PAYOUT_TONES: Record<PayoutState, StatusTone> = {
  pending: "warning",
  excluded: "neutral",
  processing: "info",
  paid: "success",
  failed: "danger",
};

export function payoutStateOf(status: string): PayoutState {
  return status in PAYOUT_TONES ? (status as PayoutState) : "pending";
}
