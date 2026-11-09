"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { success } from "@yekkola/api-client";
import {
  adminCouponsStore,
  adminCouponsUpdate,
  getAdminCouponsIndexQueryKey,
  useAdminCouponsOptions,
} from "@yekkola/api-client/endpoints/coupon";
import type { CouponResource, CouponScope } from "@yekkola/api-client/model";
import { parseMoneyInput } from "@yekkola/i18n";
import { Button } from "@yekkola/ui/components/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@yekkola/ui/components/dialog";
import { Input } from "@yekkola/ui/components/input";
import { Label } from "@yekkola/ui/components/label";
import { useTranslations } from "next-intl";
import { type FormEvent, type ReactNode, useState } from "react";

import { useSession } from "@/features/auth/session";
import { selectClass } from "@/features/common/styles";

import { CoursePicker, ProfessorPicker } from "./pickers";
import { fromLocalInput, optionalCount, toLocalInput, useFormErrorText } from "./shared";

type Target = { id: string; name: string };

/**
 * Create a platform-funded coupon, or change an existing one's operational fields (PRD-09 FR-13): state, end
 * date and limits. What a coupon is worth never changes once created; the API enforces it.
 */
export function CouponDialog({ coupon, trigger }: { coupon?: CouponResource; trigger: ReactNode }) {
  const t = useTranslations("admin.coupons");
  const f = useTranslations("admin.coupons.form");
  const common = useTranslations("common");
  const errorText = useFormErrorText();
  const session = useSession();
  const queryClient = useQueryClient();
  const editing = coupon !== undefined;
  const scopes: CouponScope[] = [
    "platform",
    ...(session.can("professors.manage") ? (["professor"] as const) : []),
    ...(session.can("catalog.view") ? (["course"] as const) : []),
  ];

  const [open, setOpen] = useState(false);
  const [code, setCode] = useState("");
  const [scope, setScope] = useState<CouponScope>("platform");
  const [professor, setProfessor] = useState<Target | null>(null);
  const [course, setCourse] = useState<Target | null>(null);
  const [type, setType] = useState<"percent" | "fixed">("percent");
  const [value, setValue] = useState("");
  // Enabled currencies from the platform settings, default first (TODO 2.2).
  const options = useAdminCouponsOptions({
    query: { select: (r) => success(r).data, staleTime: 5 * 60_000 },
  });
  const currencies = options.data?.currencies ?? [];
  const [currency, setCurrency] = useState("");
  const [startsAt, setStartsAt] = useState("");
  const [endsAt, setEndsAt] = useState("");
  const [max, setMax] = useState("");
  const [perUser, setPerUser] = useState("");
  const [active, setActive] = useState(true);

  const reset = () => {
    setCode("");
    setScope("platform");
    setProfessor(null);
    setCourse(null);
    setType("percent");
    setValue("");
    setCurrency(options.data?.default_currency ?? "");
    setStartsAt("");
    setEndsAt(toLocalInput(coupon?.ends_at));
    setMax(coupon?.max_redemptions ? String(coupon.max_redemptions) : "");
    setPerUser(coupon?.per_user_limit ? String(coupon.per_user_limit) : "");
    setActive(coupon?.is_active ?? true);
  };

  const amount =
    type === "percent"
      ? /^\d+$/.test(value.trim()) && Number(value) >= 1 && Number(value) <= 100
        ? Number(value)
        : null
      : currency
        ? parseMoneyInput(value, currency)
        : null;
  const maxCount = optionalCount(max);
  const perUserCount = optionalCount(perUser);
  // The start can't change once created: compare an edited end date with the stored start.
  const start = editing ? toLocalInput(coupon.starts_at) : startsAt;
  const datesOk = !start || !endsAt || new Date(endsAt) > new Date(start);
  const limitsOk = !Number.isNaN(maxCount) && !Number.isNaN(perUserCount);
  const valid = editing
    ? limitsOk && datesOk
    : /^[A-Za-z0-9_-]{6,40}$/.test(code.trim()) &&
      amount !== null &&
      amount > 0 &&
      datesOk &&
      limitsOk &&
      (scope !== "professor" || professor !== null) &&
      (scope !== "course" || course !== null);

  const save = useMutation({
    mutationFn: async () => {
      const limits = {
        max_redemptions: maxCount,
        per_user_limit: perUserCount,
        ends_at: fromLocalInput(endsAt),
      };
      if (coupon) {
        // Only what changed, so the audit log shows only real changes.
        await adminCouponsUpdate(coupon.id, {
          ...(maxCount !== coupon.max_redemptions ? { max_redemptions: maxCount } : {}),
          ...(perUserCount !== coupon.per_user_limit ? { per_user_limit: perUserCount } : {}),
          ...(endsAt !== toLocalInput(coupon.ends_at) ? { ends_at: limits.ends_at } : {}),
          ...(active !== coupon.is_active ? { is_active: active } : {}),
        });
        return;
      }

      await adminCouponsStore({
        ...limits,
        code: code.trim(),
        scope,
        professor_profile_id: scope === "professor" ? (professor?.id ?? null) : null,
        course_id: scope === "course" ? (course?.id ?? null) : null,
        discount_type: type,
        value: amount ?? 0,
        currency: type === "fixed" ? currency : null,
        funded_by: "platform",
        starts_at: fromLocalInput(startsAt),
        is_active: active,
      });
    },
    onSuccess: async () => {
      setOpen(false);
      await queryClient.invalidateQueries({ queryKey: getAdminCouponsIndexQueryKey() });
    },
  });

  const target = (selected: Target | null, clear: () => void, picker: ReactNode, label: string) =>
    selected ? (
      <div className="flex items-center justify-between gap-2 rounded-md border px-3 py-2 text-sm">
        <span className="grid">
          <span className="text-xs text-muted-foreground">{label}</span>
          <span className="font-semibold">{selected.name}</span>
        </span>
        <Button type="button" size="sm" variant="ghost" onClick={clear}>
          {f("change")}
        </Button>
      </div>
    ) : (
      picker
    );

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next);
        if (next) {
          reset();
          save.reset();
        }
      }}
    >
      <DialogTrigger asChild>{trigger}</DialogTrigger>
      <DialogContent closeLabel={common("close")} className="max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{coupon ? f("editTitle", { code: coupon.code }) : f("createTitle")}</DialogTitle>
          <DialogDescription>{editing ? f("editHint") : f("createHint")}</DialogDescription>
        </DialogHeader>
        <form
          noValidate
          className="grid gap-4"
          onSubmit={(e: FormEvent) => {
            e.preventDefault();
            if (valid && !save.isPending) save.mutate();
          }}
        >
          {editing ? null : (
            <>
              <div className="grid gap-2">
                <Label htmlFor="coupon-code">{f("code")}</Label>
                <Input
                  id="coupon-code"
                  value={code}
                  maxLength={40}
                  autoComplete="off"
                  className="uppercase"
                  onChange={(e) => setCode(e.target.value)}
                />
                <p className="text-xs text-muted-foreground">{f("codeHint")}</p>
              </div>

              <fieldset className="grid gap-2">
                <legend className="mb-1 text-sm font-semibold">{f("scope")}</legend>
                {scopes.map((value) => (
                  <label key={value} className="flex items-center gap-2 text-sm">
                    <input
                      type="radio"
                      name="coupon-scope"
                      checked={scope === value}
                      onChange={() => setScope(value)}
                      className="size-4 accent-primary"
                    />
                    {t(`scopes.${value}`)}
                  </label>
                ))}
              </fieldset>

              {scope === "professor"
                ? target(
                    professor,
                    () => setProfessor(null),
                    <ProfessorPicker
                      label={f("professorSearch")}
                      empty={f("noResults")}
                      onPick={(p) => setProfessor({ id: p.id, name: p.display_name })}
                    />,
                    f("professor"),
                  )
                : null}
              {scope === "course"
                ? target(
                    course,
                    () => setCourse(null),
                    <CoursePicker
                      label={f("courseSearch")}
                      empty={f("noResults")}
                      onPick={(c) => setCourse({ id: c.id, name: c.title ?? c.slug })}
                    />,
                    f("course"),
                  )
                : null}

              <fieldset className="grid gap-2">
                <legend className="mb-1 text-sm font-semibold">{f("discountType")}</legend>
                {(["percent", "fixed"] as const).map((value) => (
                  <label key={value} className="flex items-center gap-2 text-sm">
                    <input
                      type="radio"
                      name="coupon-type"
                      checked={type === value}
                      onChange={() => setType(value)}
                      className="size-4 accent-primary"
                    />
                    {f(`types.${value}`)}
                  </label>
                ))}
              </fieldset>

              <div className="grid gap-4 sm:grid-cols-2">
                {type === "fixed" ? (
                  <div className="grid gap-2">
                    <Label htmlFor="coupon-currency">{f("currency")}</Label>
                    <select
                      id="coupon-currency"
                      className={selectClass}
                      value={currency}
                      onChange={(e) => setCurrency(e.target.value)}
                    >
                      {currencies.map((c) => (
                        <option key={c} value={c}>
                          {c}
                        </option>
                      ))}
                    </select>
                  </div>
                ) : null}
                <div className="grid gap-2">
                  <Label htmlFor="coupon-value">
                    {type === "percent" ? f("percent") : f("amount", { currency })}
                  </Label>
                  <Input
                    id="coupon-value"
                    inputMode={type === "percent" ? "numeric" : "decimal"}
                    value={value}
                    onChange={(e) => setValue(e.target.value)}
                  />
                  {value && (amount === null || amount <= 0) ? (
                    <p className="text-sm text-destructive">{f("badValue")}</p>
                  ) : null}
                </div>
              </div>

              <div className="grid gap-2">
                <Label htmlFor="coupon-starts">{f("startsAt")}</Label>
                <Input
                  id="coupon-starts"
                  type="datetime-local"
                  value={startsAt}
                  onChange={(e) => setStartsAt(e.target.value)}
                />
              </div>
            </>
          )}

          <div className="grid gap-2">
            <Label htmlFor="coupon-ends">{f("endsAt")}</Label>
            <Input
              id="coupon-ends"
              type="datetime-local"
              value={endsAt}
              onChange={(e) => setEndsAt(e.target.value)}
            />
            {datesOk ? null : <p className="text-sm text-destructive">{f("badDates")}</p>}
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2">
              <Label htmlFor="coupon-max">{f("maxRedemptions")}</Label>
              <Input
                id="coupon-max"
                inputMode="numeric"
                value={max}
                onChange={(e) => setMax(e.target.value)}
              />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="coupon-per-user">{f("perUserLimit")}</Label>
              <Input
                id="coupon-per-user"
                inputMode="numeric"
                value={perUser}
                onChange={(e) => setPerUser(e.target.value)}
              />
            </div>
            <p className="text-xs text-muted-foreground sm:col-span-2">{f("noLimit")}</p>
          </div>

          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={active}
              onChange={(e) => setActive(e.target.checked)}
              className="size-4 accent-primary"
            />
            {f("active")}
          </label>

          {save.isError ? (
            <p role="alert" className="text-sm text-destructive">
              {errorText(save.error)}
            </p>
          ) : null}
          <div className="flex justify-end gap-2">
            <Button type="button" variant="ghost" onClick={() => setOpen(false)}>
              {common("cancel")}
            </Button>
            <Button type="submit" disabled={!valid || save.isPending} aria-busy={save.isPending}>
              {editing ? f("save") : f("create")}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  );
}
