"use client";

import { useMutation } from "@tanstack/react-query";
import { success } from "@yekkola/api-client";
import { professorCoursesUpdate } from "@yekkola/api-client/endpoints/course";
import type { Currency, StudioCourseResource } from "@yekkola/api-client/model";
import { formatMoney, type Locale, moneyInputValue, parseMoneyInput } from "@yekkola/i18n";
import { Button } from "@yekkola/ui/components/button";
import { Input } from "@yekkola/ui/components/input";
import { Label } from "@yekkola/ui/components/label";
import { Skeleton } from "@yekkola/ui/components/skeleton";
import { cn } from "@yekkola/ui/lib/utils";
import { useLocale, useTranslations } from "next-intl";
import { type FormEvent, useState } from "react";

import {
  fieldErrorsOf,
  useAuthoringOptions,
  useErrorText,
  useRefreshCourse,
  useStudioCourse,
} from "../hooks";
import { selectClass } from "../styles";
import { useUnsavedChanges } from "../unsaved";

/** Pricing tab (PRD-03 FR-09, FR-15, FR-18). */
export function PricingTab({ courseId }: { courseId: string }) {
  const course = useStudioCourse(courseId);
  const options = useAuthoringOptions();
  const errorText = useErrorText();

  if (options.isError) {
    return (
      <p role="alert" className="text-sm text-destructive">
        {errorText(options.error)}
      </p>
    );
  }
  if (!course.data || options.isPending) {
    return <Skeleton className="h-64 w-full" />;
  }

  return <PricingForm key={course.data.id} course={course.data} />;
}

/**
 * Free or a price in one of the enabled currencies. It is a course setting, not version content: it applies
 * at once, to new orders only — enrolled students keep their access (FR-15). Limits and steps come from settings.
 */
function PricingForm({ course }: { course: StudioCourseResource }) {
  const t = useTranslations("web.studio");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const refresh = useRefreshCourse(course.id);
  const options = useAuthoringOptions();
  const currencies = options.data?.currencies ?? [];
  const [mode, setMode] = useState<"free" | "paid">(course.is_free ? "free" : "paid");
  // A currency the admin has since turned off can't take new prices: start from the default one instead.
  const [currency, setCurrency] = useState(() => {
    const current = course.price?.currency;
    return current && currencies.some((c) => c.code === current)
      ? current
      : (options.data?.default_currency ?? "");
  });
  const [amount, setAmount] = useState(course.price ? moneyInputValue(course.price, locale) : "");
  const [invalid, setInvalid] = useState(false);
  const [dirty, setDirty] = useState(false);
  useUnsavedChanges(dirty);
  const limits = currencies.find((c) => c.code === currency);

  const save = useMutation({
    mutationFn: async (priceMinor: number | null) =>
      success(
        await professorCoursesUpdate(
          course.id,
          priceMinor === null
            ? { is_free: true }
            : { is_free: false, price_minor: priceMinor, currency: currency as Currency },
        ),
      ),
    onSuccess: async () => {
      setDirty(false);
      await refresh();
    },
  });
  const errors = fieldErrorsOf(save.error);
  const touched = () => {
    setDirty(true);
    setInvalid(false);
    if (save.isSuccess) save.reset();
  };
  const priceError = invalid ? t("priceInvalid") : (errors.price_minor ?? errors.currency);

  return (
    <section
      aria-labelledby="pricing-title"
      className="grid gap-5 rounded-xl border bg-background p-4 sm:p-6"
    >
      <div className="grid gap-1">
        <h2 id="pricing-title" className="text-lg font-bold">
          {t("pricingTitle")}
        </h2>
        <p className="text-sm text-muted-foreground">{t("pricingIntro")}</p>
      </div>
      <form
        className="grid gap-5"
        onSubmit={(e: FormEvent) => {
          e.preventDefault();
          if (save.isPending) return;
          if (mode === "free") {
            save.mutate(null);
            return;
          }
          const minor = parseMoneyInput(amount, currency);
          if (minor === null || minor <= 0) {
            setInvalid(true);
            return;
          }
          save.mutate(minor);
        }}
      >
        <fieldset className="grid gap-2 sm:grid-cols-2">
          <legend className="mb-2 text-sm font-semibold">{t("priceMode")}</legend>
          {(["paid", "free"] as const).map((value) => (
            <label
              key={value}
              className={cn(
                "flex cursor-pointer items-start gap-3 rounded-md border p-3",
                "has-checked:border-primary has-checked:bg-accent has-focus-visible:ring-2 has-focus-visible:ring-ring",
              )}
            >
              <input
                type="radio"
                name="price-mode"
                value={value}
                checked={mode === value}
                onChange={() => {
                  setMode(value);
                  touched();
                }}
                className="mt-1 size-4 accent-primary"
              />
              <span className="grid">
                <span className="font-semibold">{t(`priceModes.${value}`)}</span>
                <span className="text-xs text-muted-foreground">{t(`priceModeHints.${value}`)}</span>
              </span>
            </label>
          ))}
        </fieldset>

        {mode === "paid" ? (
          <div className="grid gap-4 sm:grid-cols-[10rem_1fr]">
            <div className="grid gap-2">
              <Label htmlFor="price-currency">{t("fields.currency")}</Label>
              <select
                id="price-currency"
                className={selectClass}
                value={currency}
                onChange={(e) => {
                  setCurrency(e.target.value);
                  touched();
                }}
              >
                {currencies.map((c) => (
                  <option key={c.code} value={c.code}>
                    {c.code}
                  </option>
                ))}
              </select>
            </div>
            <div className="grid gap-2">
              <Label htmlFor="price-amount">{t("fields.price")}</Label>
              <Input
                id="price-amount"
                inputMode="decimal"
                autoComplete="off"
                value={amount}
                aria-invalid={priceError ? true : undefined}
                aria-describedby="price-hint price-error"
                onChange={(e) => {
                  setAmount(e.target.value);
                  touched();
                }}
              />
              <p id="price-hint" className="text-xs text-muted-foreground">
                {limits?.min_minor != null && limits.max_minor != null
                  ? t("priceLimits", {
                      min: formatMoney({ amount_minor: limits.min_minor, currency }, locale),
                      max: formatMoney({ amount_minor: limits.max_minor, currency }, locale),
                    })
                  : null}
                {limits && limits.step_minor > 1
                  ? ` ${t("priceStep", { step: formatMoney({ amount_minor: limits.step_minor, currency }, locale) })}`
                  : null}
              </p>
              {priceError ? (
                <p id="price-error" className="text-sm text-destructive">
                  {priceError}
                </p>
              ) : null}
            </div>
          </div>
        ) : null}

        <p className="text-sm text-muted-foreground">{t("pricingGoingForward")}</p>

        <div className="flex flex-wrap items-center gap-3">
          <Button type="submit" disabled={!dirty || save.isPending} aria-busy={save.isPending}>
            {t("save")}
          </Button>
          {save.isError && !priceError ? (
            <p role="alert" className="text-sm text-destructive">
              {errorText(save.error)}
            </p>
          ) : (
            <p role="status" className="text-sm text-muted-foreground">
              {save.isSuccess && !dirty ? t("saved") : ""}
            </p>
          )}
        </div>
      </form>
    </section>
  );
}
