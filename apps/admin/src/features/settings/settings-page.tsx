"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { ApiError, success } from "@yekkola/api-client";
import {
  adminSettingsUpdate,
  getAdminSettingsShowQueryKey,
  useAdminSettingsShow,
} from "@yekkola/api-client/endpoints/settings";
import type { AdminSettingsShow200Data, UpdateSettingsRequest } from "@yekkola/api-client/model";
import { moneyToMajor, parseMoneyInput } from "@yekkola/i18n";
import { EmptyState } from "@yekkola/ui/blocks/empty-state";
import { PageHeader } from "@yekkola/ui/blocks/page-header";
import { Button } from "@yekkola/ui/components/button";
import { Input } from "@yekkola/ui/components/input";
import { Label } from "@yekkola/ui/components/label";
import { Skeleton } from "@yekkola/ui/components/skeleton";
import { useTranslations } from "next-intl";
import { type FormEvent, type ReactNode, useState } from "react";

import { useErrorText } from "@/features/common/errors";
import { selectClass } from "@/features/common/styles";

type Settings = AdminSettingsShow200Data;
type Group = keyof Settings;

const CURRENCIES = ["USD", "CDF"] as const;
const RAILS = ["orange", "airtel", "mpesa"] as const;

/**
 * Platform settings (project-context "Platform settings"): one section per group, each saved on its own. The API
 * checks every rule — including those across groups, like earnings held at least as long as the refund window —
 * and names the setting at fault. Changes apply going forward only; every save is audited.
 */
export function SettingsPage() {
  const t = useTranslations("admin.settings");
  const errorText = useErrorText();
  const settings = useAdminSettingsShow({ query: { select: (r) => success(r).data } });

  if (settings.isPending) {
    return (
      <div className="mx-auto grid max-w-4xl gap-4 px-4 py-8 sm:px-6">
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-96 w-full" />
      </div>
    );
  }
  if (settings.isError) {
    return (
      <div className="mx-auto max-w-xl px-4 py-16">
        <EmptyState title={errorText(settings.error)} />
      </div>
    );
  }

  const s = settings.data;

  return (
    <div className="mx-auto grid max-w-4xl gap-6 px-4 py-8 sm:px-6">
      <PageHeader title={t("title")} description={t("intro")} />
      <CommerceSection settings={s} />
      <PayoutsSection settings={s} />
      <NumbersSection group="protection" settings={s} />
      <CatalogSection settings={s} />
      <LearningSection settings={s} />
      <NumbersSection group="moderation" settings={s} />
    </div>
  );
}

/** Saves one group and keeps the field errors the API returns (keyed "group.setting…"). */
function useSaveGroup(group: Group) {
  const queryClient = useQueryClient();

  const save = useMutation({
    mutationFn: async (values: unknown) =>
      // `price_limits_minor` is a map by currency; the generated request type can't express it.
      success(await adminSettingsUpdate({ [group]: values } as UpdateSettingsRequest)).data,
    onSuccess: (data) =>
      queryClient.setQueryData(getAdminSettingsShowQueryKey(), {
        data: { data },
        status: 200,
        headers: new Headers(),
      }),
  });
  const fieldErrors = save.error instanceof ApiError ? save.error.fieldErrors() : {};
  const errorOf = (key: string) =>
    Object.entries(fieldErrors).find(
      ([k]) => k === `${group}.${key}` || k.startsWith(`${group}.${key}.`),
    )?.[1];

  return { save, errorOf, hasFieldErrors: Object.keys(fieldErrors).length > 0 };
}

function Section({
  group,
  title,
  save,
  hasFieldErrors,
  onSubmit,
  children,
}: {
  group: Group;
  title: string;
  save: ReturnType<typeof useSaveGroup>["save"];
  hasFieldErrors: boolean;
  onSubmit: () => void;
  children: ReactNode;
}) {
  const t = useTranslations("admin.settings");
  const errorText = useErrorText();

  return (
    <form
      noValidate
      aria-labelledby={`settings-${group}`}
      className="grid gap-4 rounded-xl border bg-background p-4 sm:p-6"
      onSubmit={(e: FormEvent) => {
        e.preventDefault();
        if (!save.isPending) onSubmit();
      }}
    >
      <h2 id={`settings-${group}`} className="text-lg font-bold">
        {title}
      </h2>
      <div className="grid gap-4 sm:grid-cols-2">{children}</div>
      <div className="flex flex-wrap items-center gap-3">
        <Button type="submit" disabled={save.isPending} aria-busy={save.isPending}>
          {t("save")}
        </Button>
        {save.isSuccess ? <p className="text-sm text-muted-foreground">{t("saved")}</p> : null}
        {save.isError ? (
          <p role="alert" className="text-sm text-destructive">
            {hasFieldErrors ? t("fixErrors") : errorText(save.error)}
          </p>
        ) : null}
      </div>
    </form>
  );
}

function Field({
  id,
  label,
  hint,
  error,
  children,
}: {
  id: string;
  label: string;
  hint?: string;
  error?: string;
  children: ReactNode;
}) {
  return (
    <div className="grid content-start gap-2">
      <Label htmlFor={id}>{label}</Label>
      {children}
      {hint ? <p className="text-xs text-muted-foreground">{hint}</p> : null}
      {error ? <p className="text-sm text-destructive">{error}</p> : null}
    </div>
  );
}

function NumberInput({
  id,
  value,
  onChange,
}: {
  id: string;
  value: number | null;
  onChange: (v: number | null) => void;
}) {
  return (
    <Input
      id={id}
      type="number"
      inputMode="numeric"
      value={value ?? ""}
      onChange={(e) => onChange(e.target.value === "" ? null : Number(e.target.value))}
    />
  );
}

function Check({
  id,
  label,
  checked,
  onChange,
}: {
  id: string;
  label: string;
  checked: boolean;
  onChange: (v: boolean) => void;
}) {
  return (
    <label htmlFor={id} className="flex items-center gap-2 text-sm font-semibold">
      <input
        id={id}
        type="checkbox"
        className="size-4 accent-primary"
        checked={checked}
        onChange={(e) => onChange(e.target.checked)}
      />
      {label}
    </label>
  );
}

function toggle<T>(list: T[], value: T, on: boolean): T[] {
  return on ? [...new Set([...list, value])] : list.filter((v) => v !== value);
}

/** Minor units ⇄ what staff type, per currency (display only; the API checks the amounts). */
const asInput = (minor: number | undefined, currency: string) =>
  minor === undefined ? "" : String(moneyToMajor({ amount_minor: minor, currency }));
const fromInput = (text: string, currency: string) => parseMoneyInput(text, currency) ?? 0;

function CommerceSection({ settings }: { settings: Settings }) {
  const t = useTranslations("admin.settings");
  const { save, errorOf, hasFieldErrors } = useSaveGroup("commerce");
  const c = settings.commerce;
  const [v, setV] = useState({
    ...c,
    share_pct: String(c.default_revenue_share_bps / 100),
    gateways: c.enabled_gateways.join(", "),
  });
  const [limits, setLimits] = useState(() =>
    Object.fromEntries(
      CURRENCIES.map((cur) => [
        cur,
        {
          min: asInput(c.price_limits_minor[cur]?.min, cur),
          max: asInput(c.price_limits_minor[cur]?.max, cur),
        },
      ]),
    ),
  );
  const set = (patch: Partial<typeof v>) => setV({ ...v, ...patch });

  return (
    <Section
      group="commerce"
      title={t("groups.commerce")}
      save={save}
      hasFieldErrors={hasFieldErrors}
      onSubmit={() =>
        save.mutate({
          enabled_currencies: v.enabled_currencies,
          default_currency: v.default_currency,
          default_revenue_share_bps: Math.round(Number(v.share_pct.replace(",", ".")) * 100),
          enabled_gateways: v.gateways
            .split(",")
            .map((g) => g.trim())
            .filter(Boolean),
          enabled_rails: v.enabled_rails,
          price_limits_minor: Object.fromEntries(
            v.enabled_currencies.map((cur) => [
              cur,
              { min: fromInput(limits[cur]?.min ?? "", cur), max: fromInput(limits[cur]?.max ?? "", cur) },
            ]),
          ),
          order_payment_timeout_minutes: v.order_payment_timeout_minutes,
          refund_window_days: v.refund_window_days,
          refund_max_progress_pct: v.refund_max_progress_pct,
          separate_payer_enabled: v.separate_payer_enabled,
        })
      }
    >
      <Field
        id="enabled-currencies"
        label={t("commerce.enabled_currencies")}
        error={errorOf("enabled_currencies")}
      >
        <div id="enabled-currencies" className="flex gap-4">
          {CURRENCIES.map((cur) => (
            <Check
              key={cur}
              id={`currency-${cur}`}
              label={cur}
              checked={v.enabled_currencies.includes(cur)}
              onChange={(on) => set({ enabled_currencies: toggle(v.enabled_currencies, cur, on) })}
            />
          ))}
        </div>
      </Field>
      <Field id="default-currency" label={t("commerce.default_currency")} error={errorOf("default_currency")}>
        <select
          id="default-currency"
          className={selectClass}
          value={v.default_currency}
          onChange={(e) => set({ default_currency: e.target.value })}
        >
          {CURRENCIES.map((cur) => (
            <option key={cur} value={cur}>
              {cur}
            </option>
          ))}
        </select>
      </Field>
      <Field
        id="share"
        label={t("commerce.default_revenue_share")}
        hint={t("commerce.default_revenue_share_hint")}
        error={errorOf("default_revenue_share_bps")}
      >
        <Input
          id="share"
          inputMode="decimal"
          value={v.share_pct}
          onChange={(e) => set({ share_pct: e.target.value })}
        />
      </Field>
      <Field
        id="enabled-rails"
        label={t("commerce.enabled_rails")}
        hint={t("commerce.enabled_rails_hint")}
        error={errorOf("enabled_rails")}
      >
        <div id="enabled-rails" className="flex flex-wrap gap-4">
          {RAILS.map((rail) => (
            <Check
              key={rail}
              id={`rail-${rail}`}
              label={t(`rails.${rail}`)}
              checked={v.enabled_rails.includes(rail)}
              onChange={(on) => set({ enabled_rails: toggle(v.enabled_rails, rail, on) })}
            />
          ))}
        </div>
      </Field>
      <Field
        id="gateways"
        label={t("commerce.enabled_gateways")}
        hint={t("commerce.enabled_gateways_hint")}
        error={errorOf("enabled_gateways")}
      >
        <Input id="gateways" value={v.gateways} onChange={(e) => set({ gateways: e.target.value })} />
      </Field>
      {v.enabled_currencies.map((cur) => (
        <Field
          key={cur}
          id={`limits-${cur}`}
          label={t("commerce.price_limits", { currency: cur })}
          error={errorOf(`price_limits_minor.${cur}`)}
        >
          <div id={`limits-${cur}`} className="grid grid-cols-2 gap-2">
            <Input
              aria-label={t("commerce.price_min", { currency: cur })}
              inputMode="decimal"
              value={limits[cur]?.min ?? ""}
              onChange={(e) =>
                setLimits({ ...limits, [cur]: { min: e.target.value, max: limits[cur]?.max ?? "" } })
              }
            />
            <Input
              aria-label={t("commerce.price_max", { currency: cur })}
              inputMode="decimal"
              value={limits[cur]?.max ?? ""}
              onChange={(e) =>
                setLimits({ ...limits, [cur]: { min: limits[cur]?.min ?? "", max: e.target.value } })
              }
            />
          </div>
        </Field>
      ))}
      <Field
        id="timeout"
        label={t("commerce.order_payment_timeout_minutes")}
        error={errorOf("order_payment_timeout_minutes")}
      >
        <NumberInput
          id="timeout"
          value={v.order_payment_timeout_minutes}
          onChange={(n) => set({ order_payment_timeout_minutes: n ?? 0 })}
        />
      </Field>
      <Field
        id="refund-window"
        label={t("commerce.refund_window_days")}
        error={errorOf("refund_window_days")}
      >
        <NumberInput
          id="refund-window"
          value={v.refund_window_days}
          onChange={(n) => set({ refund_window_days: n ?? 0 })}
        />
      </Field>
      <Field
        id="refund-progress"
        label={t("commerce.refund_max_progress_pct")}
        error={errorOf("refund_max_progress_pct")}
      >
        <NumberInput
          id="refund-progress"
          value={v.refund_max_progress_pct}
          onChange={(n) => set({ refund_max_progress_pct: n ?? 0 })}
        />
      </Field>
      <Field
        id="separate-payer"
        label={t("commerce.separate_payer")}
        error={errorOf("separate_payer_enabled")}
      >
        <Check
          id="separate-payer"
          label={t("enabled")}
          checked={v.separate_payer_enabled}
          onChange={(on) => set({ separate_payer_enabled: on })}
        />
      </Field>
    </Section>
  );
}

function PayoutsSection({ settings }: { settings: Settings }) {
  const t = useTranslations("admin.settings");
  const { save, errorOf, hasFieldErrors } = useSaveGroup("payouts");
  const [v, setV] = useState(settings.payouts);
  const [minimums, setMinimums] = useState(() =>
    Object.fromEntries(CURRENCIES.map((cur) => [cur, asInput(settings.payouts.minimum_minor[cur], cur)])),
  );
  const set = (patch: Partial<typeof v>) => setV({ ...v, ...patch });
  const currencies = settings.commerce.enabled_currencies;

  return (
    <Section
      group="payouts"
      title={t("groups.payouts")}
      save={save}
      hasFieldErrors={hasFieldErrors}
      onSubmit={() =>
        save.mutate({
          ...v,
          minimum_minor: Object.fromEntries(
            currencies.map((cur) => [cur, fromInput(minimums[cur] ?? "", cur)]),
          ),
        })
      }
    >
      <Field
        id="hold"
        label={t("payouts.earnings_hold_days")}
        hint={t("payouts.earnings_hold_days_hint")}
        error={errorOf("earnings_hold_days")}
      >
        <NumberInput
          id="hold"
          value={v.earnings_hold_days}
          onChange={(n) => set({ earnings_hold_days: n ?? 0 })}
        />
      </Field>
      <Field id="schedule" label={t("payouts.schedule")} error={errorOf("schedule")}>
        <select
          id="schedule"
          className={selectClass}
          value={v.schedule}
          onChange={(e) => set({ schedule: e.target.value })}
        >
          {(["weekly", "biweekly", "monthly"] as const).map((s) => (
            <option key={s} value={s}>
              {t(`schedules.${s}`)}
            </option>
          ))}
        </select>
      </Field>
      {currencies.map((cur) => (
        <Field
          key={cur}
          id={`minimum-${cur}`}
          label={t("payouts.minimum", { currency: cur })}
          error={errorOf(`minimum_minor.${cur}`)}
        >
          <Input
            id={`minimum-${cur}`}
            inputMode="decimal"
            value={minimums[cur] ?? ""}
            onChange={(e) => setMinimums({ ...minimums, [cur]: e.target.value })}
          />
        </Field>
      ))}
      <Field
        id="method-hold"
        label={t("payouts.payout_method_change_hold_hours")}
        error={errorOf("payout_method_change_hold_hours")}
      >
        <NumberInput
          id="method-hold"
          value={v.payout_method_change_hold_hours}
          onChange={(n) => set({ payout_method_change_hold_hours: n ?? 0 })}
        />
      </Field>
      <Field id="fees" label={t("payouts.fees")} error={errorOf("platform_pays_disbursement_fees")}>
        <Check
          id="fees"
          label={t("payouts.platform_pays_disbursement_fees")}
          checked={v.platform_pays_disbursement_fees}
          onChange={(on) => set({ platform_pays_disbursement_fees: on })}
        />
      </Field>
    </Section>
  );
}

/** Groups made only of whole numbers. */
function NumbersSection({ group, settings }: { group: "protection" | "moderation"; settings: Settings }) {
  const t = useTranslations("admin.settings");
  const { save, errorOf, hasFieldErrors } = useSaveGroup(group);
  const [v, setV] = useState<Record<string, number>>({ ...settings[group] });

  return (
    <Section
      group={group}
      title={t(`groups.${group}`)}
      save={save}
      hasFieldErrors={hasFieldErrors}
      onSubmit={() => save.mutate(v)}
    >
      {Object.keys(v).map((key) => (
        <Field key={key} id={`${group}-${key}`} label={t(`${group}.${key}` as never)} error={errorOf(key)}>
          <NumberInput
            id={`${group}-${key}`}
            value={v[key] ?? null}
            onChange={(n) => setV({ ...v, [key]: n ?? 0 })}
          />
        </Field>
      ))}
    </Section>
  );
}

function CatalogSection({ settings }: { settings: Settings }) {
  const t = useTranslations("admin.settings");
  const { save, errorOf, hasFieldErrors } = useSaveGroup("catalog");
  const [v, setV] = useState(settings.catalog);
  const keys = Object.keys(v) as (keyof typeof v)[];

  return (
    <Section
      group="catalog"
      title={t("groups.catalog")}
      save={save}
      hasFieldErrors={hasFieldErrors}
      onSubmit={() => save.mutate(v)}
    >
      {keys.map((key) => (
        <Field
          key={key}
          id={`catalog-${key}`}
          label={t(`catalog.${key}`)}
          hint={key.startsWith("free_") ? t("catalog.unlimited_hint") : undefined}
          error={errorOf(key)}
        >
          <NumberInput
            id={`catalog-${key}`}
            value={v[key]}
            onChange={(n) => setV({ ...v, [key]: key.startsWith("free_") ? n : (n ?? 0) })}
          />
        </Field>
      ))}
    </Section>
  );
}

function LearningSection({ settings }: { settings: Settings }) {
  const t = useTranslations("admin.settings");
  const { save, errorOf, hasFieldErrors } = useSaveGroup("learning");
  const [v, setV] = useState(settings.learning);

  return (
    <Section
      group="learning"
      title={t("groups.learning")}
      save={save}
      hasFieldErrors={hasFieldErrors}
      onSubmit={() => save.mutate(v)}
    >
      <Field
        id="cert-pct"
        label={t("learning.certificate_min_completion_pct")}
        error={errorOf("certificate_min_completion_pct")}
      >
        <NumberInput
          id="cert-pct"
          value={v.certificate_min_completion_pct}
          onChange={(n) => setV({ ...v, certificate_min_completion_pct: n ?? 0 })}
        />
      </Field>
      <Field
        id="review-pct"
        label={t("learning.review_min_progress_pct")}
        error={errorOf("review_min_progress_pct")}
      >
        <NumberInput
          id="review-pct"
          value={v.review_min_progress_pct}
          onChange={(n) => setV({ ...v, review_min_progress_pct: n ?? 0 })}
        />
      </Field>
      <Field
        id="cert-quizzes"
        label={t("learning.quizzes")}
        error={errorOf("certificate_requires_quizzes_passed")}
      >
        <Check
          id="cert-quizzes"
          label={t("learning.certificate_requires_quizzes_passed")}
          checked={v.certificate_requires_quizzes_passed}
          onChange={(on) => setV({ ...v, certificate_requires_quizzes_passed: on })}
        />
      </Field>
    </Section>
  );
}
