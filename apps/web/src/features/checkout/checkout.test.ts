import { ApiError } from "@yekkola/api-client";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { idempotencyKey, isFinalAnswer, payKeyScope, withIdempotencyKey } from "./idempotency";
import { canStillPay, stateOf } from "./order-state";

const payment = (status: string) => ({ status }) as never;

describe("stateOf", () => {
  it("asks for an operator when no payment request went out", () => {
    expect(stateOf({ status: "pending", payments: [] })).toBe("choose");
  });

  it("follows the latest attempt only", () => {
    expect(stateOf({ status: "awaiting_payment", payments: [payment("pending"), payment("failed")] })).toBe(
      "waiting",
    );
    expect(stateOf({ status: "awaiting_payment", payments: [payment("failed"), payment("pending")] })).toBe(
      "failed",
    );
  });

  it("trusts the order status for results", () => {
    expect(stateOf({ status: "paid", payments: [payment("succeeded")] })).toBe("paid");
    expect(stateOf({ status: "partially_refunded", payments: [] })).toBe("paid");
    expect(stateOf({ status: "expired", payments: [payment("pending")] })).toBe("expired");
  });
});

describe("canStillPay", () => {
  const now = Date.parse("2026-10-25T10:00:00Z");

  it("is open only before the deadline", () => {
    expect(canStillPay({ status: "awaiting_payment", expires_at: "2026-10-25T10:05:00Z" }, now)).toBe(true);
    expect(canStillPay({ status: "pending", expires_at: "2026-10-25T09:59:59Z" }, now)).toBe(false);
    expect(canStillPay({ status: "paid", expires_at: "2026-10-25T10:05:00Z" }, now)).toBe(false);
  });
});

describe("idempotency keys", () => {
  beforeEach(() => {
    const store = new Map<string, string>();
    vi.stubGlobal("sessionStorage", {
      getItem: (k: string) => store.get(k) ?? null,
      setItem: (k: string, v: string) => store.set(k, v),
      removeItem: (k: string) => store.delete(k),
    });
  });
  afterEach(() => vi.unstubAllGlobals());

  it("treats only answers the API stores as final", () => {
    expect(isFinalAnswer(new ApiError(422, { code: "payment.rail_unavailable" }))).toBe(true);
    expect(isFinalAnswer(new ApiError(409, { code: "idempotency.in_progress" }))).toBe(false);
    expect(isFinalAnswer(new ApiError(503, {}))).toBe(false);
    expect(isFinalAnswer(new TypeError("Failed to fetch"))).toBe(false);
  });

  it("replays the key after a lost request and starts afresh after an answer", async () => {
    const keys: string[] = [];
    const lost = () => Promise.reject(new TypeError("Failed to fetch"));
    const refused = () => Promise.reject(new ApiError(422, {}));

    await expect(withIdempotencyKey("pay:o1", (k) => (keys.push(k), lost()))).rejects.toThrow();
    await expect(withIdempotencyKey("pay:o1", (k) => (keys.push(k), refused()))).rejects.toThrow();
    await withIdempotencyKey("pay:o1", async (k) => keys.push(k));

    expect(keys[1]).toBe(keys[0]);
    expect(keys[2]).not.toBe(keys[1]);
    expect(idempotencyKey("pay:o1")).not.toBe(keys[2]);
  });

  it("uses a new key once a lost payment request shows up as an attempt", async () => {
    const before = { id: "o1", payments: [] };
    const after = { id: "o1", payments: [payment("failed")] };
    const lost = () => Promise.reject(new TypeError("Failed to fetch"));

    let first = "";
    await expect(withIdempotencyKey(payKeyScope(before), (k) => ((first = k), lost()))).rejects.toThrow();

    expect(idempotencyKey(payKeyScope(before))).toBe(first);
    expect(idempotencyKey(payKeyScope(after))).not.toBe(first);
  });
});
