"use client";

import { Input } from "@yekkola/ui/components/input";
import { Label } from "@yekkola/ui/components/label";
import { useTranslations } from "next-intl";

/** The mobile money number that gets the payment request; the API validates it (PRD-05 FR-05). */
export function PayerInput({
  id,
  value,
  onChange,
}: {
  id: string;
  value: string;
  onChange: (value: string) => void;
}) {
  const t = useTranslations("web.checkout");

  return (
    <div className="grid gap-2">
      <Label htmlFor={id}>{t("payer")}</Label>
      <Input
        id={id}
        type="tel"
        inputMode="tel"
        autoComplete="tel"
        value={value}
        aria-describedby={`${id}-hint`}
        onChange={(e) => onChange(e.target.value)}
      />
      <p id={`${id}-hint`} className="text-xs text-muted-foreground">
        {t("payerHint")}
      </p>
    </div>
  );
}

/** What the student typed, without spaces; empty means "my account number". */
export function msisdnOf(value: string): string | null {
  return value.replace(/\s/g, "") || null;
}
