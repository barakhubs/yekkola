import type * as React from "react";

import { Badge } from "../components/badge";

type StatusTone = "neutral" | "info" | "success" | "warning" | "danger" | "brand" | "accent";

const toneVariant = {
  neutral: "secondary",
  info: "info",
  success: "success",
  warning: "warning",
  danger: "destructive",
  brand: "default",
  accent: "accent",
} as const satisfies Record<StatusTone, React.ComponentProps<typeof Badge>["variant"]>;

interface StatusBadgeProps<S extends string> extends Omit<React.ComponentProps<"span">, "children"> {
  status: S;
  /** One map per enum (e.g. order status → tone), kept with the feature that owns the enum. */
  tones: Record<S, StatusTone>;
  /** Translated label for the status. */
  label: React.ReactNode;
  icon?: React.ReactNode;
}

/**
 * A status with a colour and a label — never colour alone (WCAG). Generic: apps pass the tone map for
 * their enum and the translated label.
 */
function StatusBadge<S extends string>({ status, tones, label, icon, ...props }: StatusBadgeProps<S>) {
  return (
    <Badge variant={toneVariant[tones[status]]} data-status={status} {...props}>
      {icon}
      {label}
    </Badge>
  );
}

export { StatusBadge };
export type { StatusBadgeProps, StatusTone };
