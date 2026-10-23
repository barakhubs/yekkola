import { formatMoney, type Locale, type MoneyValue } from "@yekkola/i18n";
import type * as React from "react";

import { cn } from "../lib/utils";

interface MoneyTextProps extends Omit<React.ComponentProps<"span">, "children"> {
  value: MoneyValue;
  locale: Locale;
}

/** An amount from the API (minor units + currency), formatted for the reader. Never computes prices. */
function MoneyText({ value, locale, className, ...props }: MoneyTextProps) {
  return (
    <span className={cn("tabular-nums", className)} {...props}>
      {formatMoney(value, locale)}
    </span>
  );
}

export { MoneyText };
export type { MoneyTextProps };
