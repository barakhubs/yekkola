"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { success } from "@yekkola/api-client";
import {
  getProfessorBundlesIndexQueryKey,
  professorBundlesStore,
  professorBundlesUpdate,
  useProfessorBundlesIndex,
} from "@yekkola/api-client/endpoints/bundle";
import {
  getProfessorCouponsIndexQueryKey,
  professorCouponsStore,
  professorCouponsUpdate,
  useProfessorCouponsIndex,
} from "@yekkola/api-client/endpoints/coupon";
import { useProfessorCoursesIndex } from "@yekkola/api-client/endpoints/course";
import type { BundleResource, BundleStatus, CouponResource } from "@yekkola/api-client/model";
import { formatDateTime, formatMoney, moneyInputValue, parseMoneyInput, type Locale } from "@yekkola/i18n";
import { StatusBadge, type StatusTone } from "@yekkola/ui/blocks/status-badge";
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
import { Skeleton } from "@yekkola/ui/components/skeleton";
import { Textarea } from "@yekkola/ui/components/textarea";
import { useLocale, useTranslations } from "next-intl";
import { type FormEvent, type ReactNode, useState } from "react";

import { useErrorText } from "../hooks";
import { selectClass } from "../styles";

type OwnCourse = { id: string; title: string; price: { amount_minor: number; currency: string } };

const COUPON_TONES: Record<string, StatusTone> = {
  active: "success",
  inactive: "neutral",
  scheduled: "info",
  expired: "neutral",
  used_up: "warning",
};
const BUNDLE_TONES: Record<string, StatusTone> = { draft: "neutral", active: "success", archived: "neutral" };
const BUNDLE_STATES: BundleStatus[] = ["draft", "active", "archived"];

/** ISO date-time → `<input type="datetime-local">` value in the professor's time zone. */
function toLocalInput(iso: string | null | undefined): string {
  if (!iso) return "";
  const d = new Date(iso);
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}
const fromLocalInput = (value: string) => (value ? new Date(value).toISOString() : null);
/** "" → null; a positive whole number; NaN otherwise. */
function optionalCount(text: string): number | null {
  const v = text.trim();
  if (v === "") return null;
  return /^\d+$/.test(v) && Number(v) > 0 ? Number(v) : Number.NaN;
}

/** Published paid courses of this professor: the only ones a coupon or bundle can use. */
function useOwnCourses() {
  return useProfessorCoursesIndex(
    { per_page: 100 },
    {
      query: {
        select: (r) =>
          success(r)
            .data.filter((c) => c.status === "published" && !c.is_free && c.price !== null)
            .map((c) => ({ id: c.id, title: c.title ?? c.slug, price: c.price! }) as OwnCourse),
      },
    },
  );
}

/**
 * The professor's own promotions (PRD-05 FR-12, FR-13): discount codes they fund, on one course or all their
 * courses, and bundles of their courses sold below their total. The API checks every rule again (own courses,
 * currency, collectable price) and applies changes to new orders only.
 */
export function PromotionsPage() {
  const t = useTranslations("web.promotions");
  const courses = useOwnCourses();

  return (
    <div className="mx-auto grid max-w-5xl gap-10 px-4 py-8">
      <header className="grid gap-1">
        <h1 className="text-2xl font-extrabold">{t("title")}</h1>
        <p className="text-muted-foreground">{t("intro")}</p>
      </header>
      {courses.data && courses.data.length === 0 ? (
        <p role="status" className="rounded-md border px-3 py-2 text-sm">
          {t("noPaidCourses")}
        </p>
      ) : null}
      <CouponsSection courses={courses.data ?? []} />
      <BundlesSection courses={courses.data ?? []} />
    </div>
  );
}

function CouponsSection({ courses }: { courses: OwnCourse[] }) {
  const t = useTranslations("web.promotions");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const coupons = useProfessorCouponsIndex({ per_page: 50 }, { query: { select: (r) => success(r).data } });
  const name = (id: string | null) =>
    id ? (courses.find((c) => c.id === id)?.title ?? t("aCourse")) : t("allCourses");

  return (
    <section aria-labelledby="coupons" className="grid gap-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 id="coupons" className="text-lg font-bold">
          {t("couponsTitle")}
        </h2>
        {courses.length > 0 ? (
          <CouponDialog courses={courses} trigger={<Button>{t("newCoupon")}</Button>} />
        ) : null}
      </div>
      <p className="text-sm text-muted-foreground">{t("couponsHint")}</p>
      {coupons.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(coupons.error)}
        </p>
      ) : coupons.isPending ? (
        <Skeleton className="h-24 w-full" />
      ) : coupons.data.length === 0 ? (
        <p className="text-sm text-muted-foreground">{t("noCoupons")}</p>
      ) : (
        <ul className="grid gap-2">
          {coupons.data.map((c) => (
            <li
              key={c.id}
              className="flex flex-wrap items-center justify-between gap-3 rounded-md border px-3 py-2"
            >
              <span className="grid gap-0.5">
                <span className="font-mono font-semibold">{c.code}</span>
                <span className="text-sm">
                  {c.discount_type === "percent"
                    ? t("percentOff", { value: c.value })
                    : t("amountOff", {
                        amount: c.currency
                          ? formatMoney({ amount_minor: c.value, currency: c.currency }, locale)
                          : c.value,
                      })}{" "}
                  · {name(c.course_id)}
                </span>
                <span className="text-xs text-muted-foreground">
                  {t("uses", { uses: c.uses, max: c.max_redemptions ?? "∞" })}
                  {c.ends_at ? ` · ${t("endsOn", { date: formatDateTime(c.ends_at, locale) })}` : ""}
                </span>
              </span>
              <span className="flex items-center gap-2">
                <StatusBadge status={c.state} tones={COUPON_TONES} label={t(`couponState.${c.state}`)} />
                <CouponDialog
                  courses={courses}
                  coupon={c}
                  trigger={
                    <Button size="sm" variant="outline">
                      {t("edit")}
                    </Button>
                  }
                />
              </span>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

/** Create a coupon (code, course or all courses, discount, dates, limits) or change its dates, limits and state. */
function CouponDialog({
  courses,
  coupon,
  trigger,
}: {
  courses: OwnCourse[];
  coupon?: CouponResource;
  trigger: ReactNode;
}) {
  const t = useTranslations("web.promotions");
  const common = useTranslations("common");
  const errorText = useErrorText();
  const queryClient = useQueryClient();
  const editing = coupon !== undefined;

  const [open, setOpen] = useState(false);
  const [code, setCode] = useState("");
  const [courseId, setCourseId] = useState("");
  const [type, setType] = useState<"percent" | "fixed">("percent");
  const [value, setValue] = useState("");
  const [startsAt, setStartsAt] = useState("");
  const [endsAt, setEndsAt] = useState("");
  const [max, setMax] = useState("");
  const [perUser, setPerUser] = useState("");
  const [active, setActive] = useState(true);

  // A fixed amount is in the course's currency; for "all my courses" the professor picks one of their currencies.
  const currencies = [...new Set(courses.map((c) => c.price.currency))];
  const [allCurrency, setAllCurrency] = useState("");
  const currency =
    courses.find((c) => c.id === courseId)?.price.currency ?? (allCurrency || currencies[0] || "");
  const amount =
    type === "percent"
      ? /^\d+$/.test(value.trim()) && Number(value) >= 1 && Number(value) <= 100
        ? Number(value)
        : null
      : parseMoneyInput(value, currency);
  const maxCount = optionalCount(max);
  const perUserCount = optionalCount(perUser);
  const start = editing ? toLocalInput(coupon.starts_at) : startsAt;
  const datesOk = !start || !endsAt || new Date(endsAt) > new Date(start);
  const limitsOk = !Number.isNaN(maxCount) && !Number.isNaN(perUserCount);
  const valid = editing
    ? datesOk && limitsOk
    : /^[A-Za-z0-9_-]{6,40}$/.test(code.trim()) && amount !== null && amount > 0 && datesOk && limitsOk;

  const save = useMutation({
    mutationFn: async () => {
      if (coupon) {
        await professorCouponsUpdate(coupon.id, {
          ...(maxCount !== coupon.max_redemptions ? { max_redemptions: maxCount } : {}),
          ...(perUserCount !== coupon.per_user_limit ? { per_user_limit: perUserCount } : {}),
          ...(endsAt !== toLocalInput(coupon.ends_at) ? { ends_at: fromLocalInput(endsAt) } : {}),
          ...(active !== coupon.is_active ? { is_active: active } : {}),
        });
        return;
      }
      await professorCouponsStore({
        code: code.trim(),
        course_id: courseId || null,
        discount_type: type,
        value: amount ?? 0,
        currency: type === "fixed" ? currency : null,
        starts_at: fromLocalInput(startsAt),
        ends_at: fromLocalInput(endsAt),
        max_redemptions: maxCount,
        per_user_limit: perUserCount,
        is_active: active,
      });
    },
    onSuccess: async () => {
      setOpen(false);
      await queryClient.invalidateQueries({ queryKey: getProfessorCouponsIndexQueryKey() });
    },
  });

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next);
        if (!next) return;
        setCode("");
        setCourseId("");
        setType("percent");
        setValue("");
        setStartsAt("");
        setEndsAt(toLocalInput(coupon?.ends_at));
        setMax(coupon?.max_redemptions ? String(coupon.max_redemptions) : "");
        setPerUser(coupon?.per_user_limit ? String(coupon.per_user_limit) : "");
        setActive(coupon?.is_active ?? true);
        save.reset();
      }}
    >
      <DialogTrigger asChild>{trigger}</DialogTrigger>
      <DialogContent closeLabel={common("close")} className="max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{coupon ? t("editCoupon", { code: coupon.code }) : t("newCoupon")}</DialogTitle>
          <DialogDescription>{editing ? t("editCouponHint") : t("newCouponHint")}</DialogDescription>
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
              <Field id="coupon-code" label={t("code")} hint={t("codeHint")}>
                <Input
                  id="coupon-code"
                  value={code}
                  maxLength={40}
                  autoComplete="off"
                  className="uppercase"
                  onChange={(e) => setCode(e.target.value)}
                />
              </Field>
              <Field id="coupon-course" label={t("appliesTo")}>
                <select
                  id="coupon-course"
                  className={selectClass}
                  value={courseId}
                  onChange={(e) => setCourseId(e.target.value)}
                >
                  <option value="">{t("allCourses")}</option>
                  {courses.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.title}
                    </option>
                  ))}
                </select>
              </Field>
              <fieldset className="grid gap-2">
                <legend className="mb-1 text-sm font-semibold">{t("discountType")}</legend>
                {(["percent", "fixed"] as const).map((v) => (
                  <label key={v} className="flex items-center gap-2 text-sm">
                    <input
                      type="radio"
                      name="coupon-type"
                      checked={type === v}
                      onChange={() => setType(v)}
                      className="size-4 accent-primary"
                    />
                    {t(`types.${v}`)}
                  </label>
                ))}
              </fieldset>
              <Field id="coupon-value" label={type === "percent" ? t("percent") : t("amount", { currency })}>
                <Input
                  id="coupon-value"
                  inputMode={type === "percent" ? "numeric" : "decimal"}
                  value={value}
                  onChange={(e) => setValue(e.target.value)}
                />
                {type === "fixed" && !courseId && currencies.length > 1 ? (
                  <select
                    aria-label={t("currency")}
                    className={selectClass}
                    value={currency}
                    onChange={(e) => setAllCurrency(e.target.value)}
                  >
                    {currencies.map((c) => (
                      <option key={c} value={c}>
                        {c}
                      </option>
                    ))}
                  </select>
                ) : null}
                {value && (amount === null || amount <= 0) ? (
                  <p className="text-sm text-destructive">{t("badValue")}</p>
                ) : null}
              </Field>
              <Field id="coupon-starts" label={t("startsAt")}>
                <Input
                  id="coupon-starts"
                  type="datetime-local"
                  value={startsAt}
                  onChange={(e) => setStartsAt(e.target.value)}
                />
              </Field>
            </>
          )}
          <Field id="coupon-ends" label={t("endsAt")}>
            <Input
              id="coupon-ends"
              type="datetime-local"
              value={endsAt}
              onChange={(e) => setEndsAt(e.target.value)}
            />
            {datesOk ? null : <p className="text-sm text-destructive">{t("badDates")}</p>}
          </Field>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field id="coupon-max" label={t("maxUses")}>
              <Input
                id="coupon-max"
                inputMode="numeric"
                value={max}
                onChange={(e) => setMax(e.target.value)}
              />
            </Field>
            <Field id="coupon-per-user" label={t("perStudent")}>
              <Input
                id="coupon-per-user"
                inputMode="numeric"
                value={perUser}
                onChange={(e) => setPerUser(e.target.value)}
              />
            </Field>
            <p className="text-xs text-muted-foreground sm:col-span-2">
              {limitsOk ? t("noLimit") : t("badLimit")}
            </p>
          </div>
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={active}
              onChange={(e) => setActive(e.target.checked)}
              className="size-4 accent-primary"
            />
            {t("active")}
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
              {editing ? t("save") : t("create")}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function BundlesSection({ courses }: { courses: OwnCourse[] }) {
  const t = useTranslations("web.promotions");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const bundles = useProfessorBundlesIndex({ per_page: 50 }, { query: { select: (r) => success(r).data } });

  return (
    <section aria-labelledby="bundles" className="grid gap-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 id="bundles" className="text-lg font-bold">
          {t("bundlesTitle")}
        </h2>
        {courses.length >= 2 ? (
          <BundleDialog courses={courses} trigger={<Button>{t("newBundle")}</Button>} />
        ) : null}
      </div>
      <p className="text-sm text-muted-foreground">
        {courses.length >= 2 ? t("bundlesHint") : t("bundlesNeedTwo")}
      </p>
      {bundles.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(bundles.error)}
        </p>
      ) : bundles.isPending ? (
        <Skeleton className="h-24 w-full" />
      ) : bundles.data.length === 0 ? (
        <p className="text-sm text-muted-foreground">{t("noBundles")}</p>
      ) : (
        <ul className="grid gap-2">
          {bundles.data.map((b) => (
            <li
              key={b.id}
              className="flex flex-wrap items-center justify-between gap-3 rounded-md border px-3 py-2"
            >
              <span className="grid gap-0.5">
                <span className="font-semibold">{b.title}</span>
                <span className="text-sm">
                  {formatMoney(b.price, locale)}{" "}
                  <span className="text-muted-foreground line-through">
                    {formatMoney(b.list_total, locale)}
                  </span>{" "}
                  · {t("courseCount", { count: b.courses.length })}
                </span>
              </span>
              <span className="flex items-center gap-2">
                <StatusBadge
                  status={b.status}
                  tones={BUNDLE_TONES}
                  label={t(`bundleState.${b.status}` as never)}
                />
                <BundleDialog
                  courses={courses}
                  bundle={b}
                  trigger={
                    <Button size="sm" variant="outline">
                      {t("edit")}
                    </Button>
                  }
                />
              </span>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

/** Two to twenty of the professor's published paid courses in one currency, priced below their total. */
function BundleDialog({
  courses,
  bundle,
  trigger,
}: {
  courses: OwnCourse[];
  bundle?: BundleResource;
  trigger: ReactNode;
}) {
  const t = useTranslations("web.promotions");
  const common = useTranslations("common");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const queryClient = useQueryClient();

  const [open, setOpen] = useState(false);
  const [title, setTitle] = useState("");
  const [description, setDescription] = useState("");
  const [selected, setSelected] = useState<string[]>([]);
  const [price, setPrice] = useState("");
  const [status, setStatus] = useState<BundleStatus>("draft");

  // The bundle's own courses stay listed even if one went off sale since, so editing never drops them silently.
  const sellable = new Set(courses.map((c) => c.id));
  const options: (OwnCourse & { sellable: boolean })[] = [
    ...courses.map((c) => ({ ...c, sellable: true })),
    ...(bundle?.courses ?? [])
      .filter((c) => !sellable.has(c.id))
      .map((c) => ({
        id: c.id,
        title: c.title ?? c.slug,
        price: c.price ?? { amount_minor: 0, currency: bundle?.price.currency ?? "" },
        sellable: false,
      })),
  ];
  const picked = options.filter((c) => selected.includes(c.id));
  const currency = picked[0]?.price.currency ?? bundle?.price.currency ?? null;
  const sameCurrency = picked.every((c) => c.price.currency === currency);
  const listTotal = picked.reduce((sum, c) => sum + c.price.amount_minor, 0);
  const priceMinor = currency ? parseMoneyInput(price, currency) : null;
  const priceOk = priceMinor !== null && priceMinor > 0 && priceMinor < listTotal;
  const contentValid =
    picked.length >= 2 && picked.length <= 20 && picked.every((c) => c.sellable) && sameCurrency && priceOk;

  const initialIds = bundle?.courses.map((c) => c.id) ?? [];
  const coursesChanged =
    selected.length !== initialIds.length || selected.some((id, i) => id !== initialIds[i]);
  const priceChanged = bundle ? price !== moneyInputValue(bundle.price, locale) : true;
  const contentChanged = !bundle || coursesChanged || priceChanged;
  const titleChanged = bundle ? title.trim() !== bundle.title : true;
  const descriptionChanged = bundle ? (description.trim() || null) !== (bundle.description ?? null) : true;
  // Taking a bundle off sale must always work: a status-only change is sent alone (the API then skips its checks).
  const valid = title.trim().length >= 3 && (!contentChanged || contentValid);

  const save = useMutation({
    mutationFn: async () => {
      if (!bundle) {
        await professorBundlesStore({
          title: title.trim(),
          description: description.trim() || null,
          price_minor: priceMinor ?? 0,
          currency: currency ?? "",
          status,
          course_ids: picked.map((c) => c.id),
        });
        return;
      }
      await professorBundlesUpdate(bundle.id, {
        ...(titleChanged ? { title: title.trim() } : {}),
        ...(descriptionChanged ? { description: description.trim() || null } : {}),
        ...(contentChanged
          ? { price_minor: priceMinor ?? 0, currency: currency ?? "", course_ids: picked.map((c) => c.id) }
          : {}),
        ...(status !== bundle.status ? { status } : {}),
      });
    },
    onSuccess: async () => {
      setOpen(false);
      await queryClient.invalidateQueries({ queryKey: getProfessorBundlesIndexQueryKey() });
    },
  });

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next);
        if (!next) return;
        setTitle(bundle?.title ?? "");
        setDescription(bundle?.description ?? "");
        setSelected(bundle?.courses.map((c) => c.id) ?? []);
        setPrice(bundle ? moneyInputValue(bundle.price, locale) : "");
        setStatus((bundle?.status as BundleStatus | undefined) ?? "draft");
        save.reset();
      }}
    >
      <DialogTrigger asChild>{trigger}</DialogTrigger>
      <DialogContent closeLabel={common("close")} className="max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{bundle ? t("editBundle", { title: bundle.title }) : t("newBundle")}</DialogTitle>
          <DialogDescription>{t("bundleHint")}</DialogDescription>
        </DialogHeader>
        <form
          noValidate
          className="grid gap-4"
          onSubmit={(e: FormEvent) => {
            e.preventDefault();
            if (valid && !save.isPending) save.mutate();
          }}
        >
          <Field id="bundle-title" label={t("bundleName")}>
            <Input
              id="bundle-title"
              maxLength={120}
              value={title}
              onChange={(e) => setTitle(e.target.value)}
            />
          </Field>
          <Field id="bundle-description" label={t("description")}>
            <Textarea
              id="bundle-description"
              rows={3}
              maxLength={2000}
              value={description}
              onChange={(e) => setDescription(e.target.value)}
            />
          </Field>
          <fieldset className="grid gap-2">
            <legend className="mb-1 text-sm font-semibold">{t("bundleCourses")}</legend>
            {options.map((c) => (
              <label
                key={c.id}
                className="flex items-center justify-between gap-2 rounded-md border px-3 py-1.5 text-sm"
              >
                <span className="flex items-center gap-2">
                  <input
                    type="checkbox"
                    checked={selected.includes(c.id)}
                    disabled={
                      !selected.includes(c.id) &&
                      (!c.sellable || (currency !== null && c.price.currency !== currency))
                    }
                    onChange={(e) =>
                      setSelected((list) =>
                        e.target.checked ? [...list, c.id] : list.filter((id) => id !== c.id),
                      )
                    }
                    className="size-4 accent-primary"
                  />
                  {c.title}
                </span>
                <span className={c.sellable ? "text-muted-foreground" : "text-destructive"}>
                  {c.sellable ? formatMoney(c.price, locale) : t("notOnSale")}
                </span>
              </label>
            ))}
            {currency && picked.length > 0 ? (
              <p className="text-xs text-muted-foreground">
                {t("listTotal", { total: formatMoney({ amount_minor: listTotal, currency }, locale) })}
              </p>
            ) : null}
            {picked.some((c) => !c.sellable) ? (
              <p className="text-sm text-destructive">{t("courseNoLongerSellable")}</p>
            ) : null}
          </fieldset>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field id="bundle-price" label={t("bundlePrice", { currency: currency ?? "—" })}>
              <Input
                id="bundle-price"
                inputMode="decimal"
                disabled={currency === null}
                value={price}
                onChange={(e) => setPrice(e.target.value)}
              />
              {price && !priceOk ? <p className="text-sm text-destructive">{t("badBundlePrice")}</p> : null}
            </Field>
            <Field id="bundle-status" label={t("status")}>
              <select
                id="bundle-status"
                className={selectClass}
                value={status}
                onChange={(e) => setStatus(e.target.value as BundleStatus)}
              >
                {BUNDLE_STATES.map((s) => (
                  <option key={s} value={s}>
                    {t(`bundleState.${s}`)}
                  </option>
                ))}
              </select>
            </Field>
          </div>
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
              {bundle ? t("save") : t("create")}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function Field({
  id,
  label,
  hint,
  children,
}: {
  id: string;
  label: string;
  hint?: string;
  children: ReactNode;
}) {
  return (
    <div className="grid gap-2">
      <Label htmlFor={id}>{label}</Label>
      {children}
      {hint ? <p className="text-xs text-muted-foreground">{hint}</p> : null}
    </div>
  );
}
