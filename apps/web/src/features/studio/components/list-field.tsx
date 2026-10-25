"use client";

import { Button } from "@yekkola/ui/components/button";
import { Input } from "@yekkola/ui/components/input";
import { Plus, X } from "lucide-react";
import { useTranslations } from "next-intl";
import { useId, useState } from "react";

let nextKey = 0;

/**
 * A short list of lines (outcomes, requirements). Field errors come back per line from the API
 * (`outcomes.2`), so each line shows its own.
 */
export function ListField({
  name,
  label,
  hint,
  items,
  max,
  disabled,
  errors,
  onChange,
}: {
  name: string;
  label: string;
  hint?: string;
  items: string[];
  max: number;
  disabled?: boolean;
  errors: Record<string, string>;
  onChange: (items: string[]) => void;
}) {
  const t = useTranslations("web.studio");
  const id = useId();
  const listError = errors[name];
  // Stable keys per line, so removing a middle line keeps focus and input state on the right rows.
  const [keys, setKeys] = useState<number[]>(() => items.map(() => nextKey++));
  if (keys.length !== items.length) {
    setKeys(
      keys.length < items.length
        ? [...keys, ...items.slice(keys.length).map(() => nextKey++)]
        : keys.slice(0, items.length),
    );
  }

  return (
    <fieldset className="grid gap-2" disabled={disabled}>
      <legend className="mb-1 text-sm font-semibold">{label}</legend>
      {hint ? (
        <p id={`${id}-hint`} className="-mt-1 text-xs text-muted-foreground">
          {hint}
        </p>
      ) : null}
      <ol className="grid gap-2">
        {items.map((item, index) => {
          const error = errors[`${name}.${index}`];
          return (
            <li key={keys[index] ?? `extra-${index}`} className="grid gap-1">
              <div className="flex gap-2">
                <Input
                  value={item}
                  maxLength={160}
                  aria-label={t("listItem", { label, number: index + 1 })}
                  aria-invalid={error ? true : undefined}
                  onChange={(e) => onChange(items.map((v, i) => (i === index ? e.target.value : v)))}
                />
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  aria-label={t("removeItem", { number: index + 1 })}
                  onClick={() => {
                    setKeys(keys.filter((_, i) => i !== index));
                    onChange(items.filter((_, i) => i !== index));
                  }}
                >
                  <X aria-hidden />
                </Button>
              </div>
              {error ? <p className="text-sm text-destructive">{error}</p> : null}
            </li>
          );
        })}
      </ol>
      {listError ? <p className="text-sm text-destructive">{listError}</p> : null}
      {items.length < max ? (
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="w-fit"
          onClick={() => onChange([...items, ""])}
        >
          <Plus aria-hidden />
          {t("addItem")}
        </Button>
      ) : null}
    </fieldset>
  );
}
