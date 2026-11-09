"use client";

import { ApiError } from "@yekkola/api-client";
import type { CouponResourceState } from "@yekkola/api-client/model";
import type { StatusTone } from "@yekkola/ui/blocks/status-badge";
import { useTranslations } from "next-intl";
import { useCallback, useEffect, useState } from "react";

export const COUPON_TONES: Record<CouponResourceState, StatusTone> = {
  active: "success",
  inactive: "neutral",
  scheduled: "info",
  expired: "neutral",
  used_up: "warning",
};

export type BundleState = "draft" | "active" | "archived";

export const BUNDLE_TONES: Record<BundleState, StatusTone> = {
  draft: "neutral",
  active: "success",
  archived: "neutral",
};

export const BUNDLE_STATES = Object.keys(BUNDLE_TONES) as BundleState[];

export function bundleStateOf(status: string): BundleState {
  return status in BUNDLE_TONES ? (status as BundleState) : "draft";
}

/** ISO date-time → the value of an `<input type="datetime-local">` in the staff member's time zone. */
export function toLocalInput(iso: string | null | undefined): string {
  if (!iso) return "";
  const date = new Date(iso);
  const pad = (n: number) => String(n).padStart(2, "0");

  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/** `<input type="datetime-local">` value → ISO date-time, or null when empty. */
export function fromLocalInput(value: string): string | null {
  return value ? new Date(value).toISOString() : null;
}

/** An optional whole-number field: "" → null, otherwise the number (NaN when not a positive integer). */
export function optionalCount(text: string): number | null {
  const trimmed = text.trim();
  if (trimmed === "") return null;

  return /^\d+$/.test(trimmed) && Number(trimmed) > 0 ? Number(trimmed) : Number.NaN;
}

/** Form errors: the API's first validation message, its translated reason, or a generic / network message. */
export function useFormErrorText() {
  const errors = useTranslations("errors");

  return useCallback(
    (error: unknown) =>
      error instanceof ApiError
        ? (Object.values(error.fieldErrors())[0] ?? error.title ?? errors("generic"))
        : errors("network"),
    [errors],
  );
}

/** A search box's value, settled for `delay` ms, so pickers don't query on every keystroke. */
export function useDebounced<T>(value: T, delay = 300): T {
  const [settled, setSettled] = useState(value);
  useEffect(() => {
    const timer = setTimeout(() => setSettled(value), delay);
    return () => clearTimeout(timer);
  }, [value, delay]);

  return settled;
}
