import { ApiError } from "@yekkola/api-client";

/**
 * Idempotency keys for money writes (project-context: "Order creation requires an Idempotency-Key"). The same
 * logical action keeps its key across retries and reloads, so a double tap or a flaky network never creates two
 * orders or two payment requests; a new action gets a new key.
 */
export function idempotencyKey(scope: string): string {
  const storageKey = `yk-idem:${scope}`;
  try {
    const existing = sessionStorage.getItem(storageKey);
    if (existing) return existing;
    const created = crypto.randomUUID();
    sessionStorage.setItem(storageKey, created);
    return created;
  } catch {
    return crypto.randomUUID();
  }
}

/** Forget a key once its action has a final answer (the next action of that kind is a new one). */
export function forgetIdempotencyKey(scope: string): void {
  try {
    sessionStorage.removeItem(`yk-idem:${scope}`);
  } catch {
    // ignore
  }
}

/**
 * Whether the API stored this answer against the key (EnsureIdempotency keeps 2xx and 400/404/410/422). Replaying
 * the key would only return the same answer, so the next try must use a new one. Network errors, 5xx and 409s
 * are not final: retrying with the same key is what makes them safe.
 */
export function isFinalAnswer(error: unknown): boolean {
  return error instanceof ApiError && [400, 404, 410, 422].includes(error.status);
}

/**
 * Key scope for "pay this order": one per known attempt count. A request lost on the network keeps its key until
 * the API shows how it ended; once that attempt appears (and fails), the next try is a new action.
 */
export function payKeyScope(order: { id: string; payments: readonly unknown[] }): string {
  return `pay:${order.id}:${order.payments.length}`;
}

/** Runs one money write under `scope`'s key and forgets the key once the API has given a final answer. */
export async function withIdempotencyKey<T>(scope: string, run: (key: string) => Promise<T>): Promise<T> {
  try {
    const result = await run(idempotencyKey(scope));
    forgetIdempotencyKey(scope);
    return result;
  } catch (error) {
    if (isFinalAnswer(error)) forgetIdempotencyKey(scope);
    throw error;
  }
}
