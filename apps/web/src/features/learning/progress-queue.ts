import { ApiError } from "@yekkola/api-client";
import type { SyncProgressRequestItemsItem } from "@yekkola/api-client/model";

export type ProgressItem = SyncProgressRequestItemsItem;

/**
 * Combines two updates for the same lesson without losing progress: the furthest position, completion if
 * either says so, the newest timestamp. Mirrors the API's monotonic merge (PRD-06 FR-05).
 */
export function mergeProgress(a: ProgressItem | undefined, b: ProgressItem): ProgressItem {
  if (!a) return b;

  const position = Math.max(a.position_seconds ?? 0, b.position_seconds ?? 0);
  const completed = Boolean(a.completed || b.completed);
  const newest =
    (a.client_updated_at ?? "") > (b.client_updated_at ?? "") ? a.client_updated_at : b.client_updated_at;

  return {
    lesson_uid: b.lesson_uid,
    ...(position > 0 || a.position_seconds != null || b.position_seconds != null
      ? { position_seconds: position }
      : {}),
    ...(completed ? { completed: true } : {}),
    ...(newest ? { client_updated_at: newest } : {}),
  };
}

/**
 * Whether a failed batch is worth sending again: network errors, timeouts, rate limits and server errors
 * are; other client errors (no longer enrolled, invalid) would fail every time.
 */
export function shouldRetry(error: unknown): boolean {
  if (!(error instanceof ApiError)) return true;

  return error.status >= 500 || error.status === 408 || error.status === 419 || error.status === 429;
}
