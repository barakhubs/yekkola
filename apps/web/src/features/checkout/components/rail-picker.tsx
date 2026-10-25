"use client";

import type { MobileMoneyRail } from "@yekkola/api-client/model";
import { cn } from "@yekkola/ui/lib/utils";
import { useTranslations } from "next-intl";

export const RAILS = ["orange", "airtel", "mpesa"] as const satisfies readonly MobileMoneyRail[];

interface RailPickerProps {
  /** Which rails the API says are available for this currency (paused rails are false). */
  available: Partial<Record<string, boolean>>;
  value: MobileMoneyRail | null;
  onChange: (rail: MobileMoneyRail) => void;
}

/** Mobile money operator choice (PRD-05 FR-05, FR-19). Unavailable rails are shown but disabled. */
export function RailPicker({ available, value, onChange }: RailPickerProps) {
  const t = useTranslations("web.checkout");

  return (
    <fieldset className="grid gap-2">
      <legend className="mb-1 text-sm font-semibold">{t("operator")}</legend>
      <div className="grid gap-2 sm:grid-cols-3">
        {RAILS.map((rail) => {
          const enabled = available[rail] === true;
          return (
            <label
              key={rail}
              className={cn(
                "flex cursor-pointer items-center gap-3 rounded-md border p-3 has-checked:border-primary has-checked:bg-accent has-focus-visible:ring-2 has-focus-visible:ring-ring",
                !enabled && "cursor-not-allowed opacity-50",
              )}
            >
              <input
                type="radio"
                name="rail"
                value={rail}
                disabled={!enabled}
                checked={value === rail}
                onChange={() => onChange(rail)}
                className="size-4 accent-primary"
              />
              <span className="grid">
                <span className="font-semibold">{t(`rails.${rail}`)}</span>
                {enabled ? null : (
                  <span className="text-xs text-muted-foreground">{t("railUnavailable")}</span>
                )}
              </span>
            </label>
          );
        })}
      </div>
    </fieldset>
  );
}
