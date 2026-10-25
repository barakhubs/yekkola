"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ApiError, success } from "@yekkola/api-client";
import { checkoutQuote, useCheckoutRails } from "@yekkola/api-client/endpoints/checkout";
import { ordersPay, ordersStore } from "@yekkola/api-client/endpoints/order";
import type { Currency, MobileMoneyRail, OrderResource } from "@yekkola/api-client/model";
import { formatMoney, type Locale } from "@yekkola/i18n";
import { EmptyState } from "@yekkola/ui/blocks/empty-state";
import { PageHeader } from "@yekkola/ui/blocks/page-header";
import { Button } from "@yekkola/ui/components/button";
import { Input } from "@yekkola/ui/components/input";
import { Label } from "@yekkola/ui/components/label";
import { Skeleton } from "@yekkola/ui/components/skeleton";
import { ShieldCheck } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { type FormEvent, useState } from "react";

import { useSession } from "@/features/auth/hooks/use-session";
import { Link, useRouter } from "@/i18n/navigation";

import { isFinalAnswer, payKeyScope, withIdempotencyKey } from "../idempotency";
import { msisdnOf, PayerInput } from "./payer-input";
import { RailPicker } from "./rail-picker";

/** Shows what the API says: an amount the browser never computes (PRD-05 FR-02). */
function useMoney() {
  const locale = useLocale() as Locale;
  return (money: { amount_minor: number; currency: string }) => formatMoney(money, locale);
}

const quoteKey = (courseId: string, coupon: string | null) => ["checkout-quote", courseId, coupon];

/**
 * Checkout for one course (PRD-05 FR-02–FR-05): the server's quote (with an optional coupon), then the
 * operator and paying number. Creating the order and starting the payment are both idempotent, so a double tap
 * or a retry never charges twice. The waiting screen takes over once the payment request is sent.
 */
export function CheckoutForm({ courseId }: { courseId: string }) {
  const t = useTranslations("web.checkout");
  const errors = useTranslations("errors");
  const money = useMoney();
  const router = useRouter();
  const queryClient = useQueryClient();
  const { user } = useSession();
  const [coupon, setCoupon] = useState("");
  const [appliedCoupon, setAppliedCoupon] = useState<string | null>(null);
  const [rail, setRail] = useState<MobileMoneyRail | null>(null);
  const [payer, setPayer] = useState<string | null>(null);
  // Set once the order exists: a payment request the API turned down is fixed and sent again for this order.
  const [order, setOrder] = useState<OrderResource | null>(null);

  const quote = useQuery({
    queryKey: quoteKey(courseId, appliedCoupon),
    queryFn: async () =>
      success(await checkoutQuote({ course_ids: [courseId], coupon_code: appliedCoupon })).data,
    retry: false,
    // A refetch could turn an applied coupon that has since run out into a full-page error; the API checks the
    // coupon again when the order is created and the message shows by the pay button.
    refetchOnWindowFocus: false,
  });
  const rails = useCheckoutRails(
    { currency: (quote.data?.currency ?? "USD") as Currency },
    { query: { enabled: Boolean(quote.data && !quote.data.is_free), select: (r) => success(r).data } },
  );

  // A code is checked before it replaces the quote, so a bad code is an inline message, not a lost page.
  const applyCoupon = useMutation({
    mutationFn: async (code: string) =>
      success(await checkoutQuote({ course_ids: [courseId], coupon_code: code })).data,
    onSuccess: (data, code) => {
      queryClient.setQueryData(quoteKey(courseId, code), data);
      setAppliedCoupon(code);
      setCoupon("");
    },
  });
  const submitCoupon = () => {
    const code = coupon.trim();
    if (code && !applyCoupon.isPending) applyCoupon.mutate(code);
  };

  const pay = useMutation({
    mutationFn: async () => {
      const current =
        order ??
        (await withIdempotencyKey(
          `order:${courseId}:${appliedCoupon ?? ""}`,
          async (key) =>
            success(
              await ordersStore(
                { course_ids: [courseId], coupon_code: appliedCoupon },
                { headers: { "Idempotency-Key": key } },
              ),
            ).data,
        ));
      setOrder(current);
      // A free order (e.g. a 100 % coupon) is already paid; otherwise ask the operator.
      if (current.status !== "paid" && rail) {
        try {
          await withIdempotencyKey(payKeyScope(current), async (key) =>
            ordersPay(
              current.id,
              { rail, msisdn: msisdnOf(payer ?? user?.phone ?? "") },
              { headers: { "Idempotency-Key": key } },
            ),
          );
        } catch (error) {
          // Turned down (wrong number, operator just paused): say why here, unless the order itself can no
          // longer be paid (expired, …) — its page explains that. Anything else may still have reached the
          // operator, so the order page finds out and keeps the same key for a retry.
          const orderLevel = error instanceof ApiError && error.code?.startsWith("order.");
          if (isFinalAnswer(error) && !orderLevel) throw error;
        }
      }
      return current;
    },
    onSuccess: (current) => router.push(`/checkout/${current.id}`),
    onError: (error) => {
      // Another order for this course is still waiting for payment: continue that one (PRD-05 BR).
      if (error instanceof ApiError && error.code === "order.open_order_exists") {
        const open = error.body.order_id;
        if (typeof open === "string") router.push(`/checkout/${open}`);
      }
    },
  });

  const errorText = (error: unknown) =>
    error instanceof ApiError ? (error.body.message ?? errors("generic")) : errors("network");

  if (quote.isPending) {
    return (
      <div className="mx-auto grid max-w-xl gap-4 px-4 py-10">
        <Skeleton className="h-8 w-40" />
        <Skeleton className="h-40 w-full" />
      </div>
    );
  }

  if (quote.isError) {
    const owned = quote.error instanceof ApiError && quote.error.code === "order.nothing_to_buy";
    return (
      <div className="mx-auto max-w-xl px-4 py-16">
        <EmptyState
          title={owned ? t("alreadyOwned") : errorText(quote.error)}
          action={
            owned ? (
              <Button asChild>
                <Link href={`/learn/${courseId}`}>{t("goToCourse")}</Link>
              </Button>
            ) : undefined
          }
        />
      </div>
    );
  }

  const q = quote.data;
  const availableRails = rails.data ?? {};
  const anyRail = Object.values(availableRails).some(Boolean);
  const canPay = q.is_free || (rail !== null && availableRails[rail] === true);

  return (
    <form
      className="mx-auto grid max-w-xl gap-6 px-4 py-8 sm:py-12"
      onSubmit={(e: FormEvent) => {
        e.preventDefault();
        if (canPay && !pay.isPending) pay.mutate();
      }}
    >
      <PageHeader title={t("title")} />

      <section aria-labelledby="order-summary" className="grid gap-3 rounded-xl border p-4">
        <h2 id="order-summary" className="font-bold">
          {t("summary")}
        </h2>
        <ul className="grid gap-2">
          {q.items.map((item) => (
            <li key={item.course_id} className="flex justify-between gap-4">
              <span>{item.title}</span>
              <span className="tabular-nums">{money(item.list_price)}</span>
            </li>
          ))}
        </ul>
        <dl className="grid gap-1 border-t pt-3 text-sm">
          {q.discount.amount_minor > 0 ? (
            <div className="flex justify-between">
              <dt>{t("discount")}</dt>
              <dd className="tabular-nums">−{money(q.discount)}</dd>
            </div>
          ) : null}
          <div className="flex justify-between text-base font-extrabold">
            <dt>{t("total")}</dt>
            <dd className="tabular-nums">{money(q.total)}</dd>
          </div>
        </dl>
      </section>

      {/* The order's price is fixed once it exists, so the coupon can no longer change. */}
      {order ? null : appliedCoupon ? (
        <p className="flex items-center gap-3 text-sm">
          <span className="font-semibold">{t("couponApplied", { code: appliedCoupon })}</span>
          <Button type="button" variant="link" className="h-auto p-0" onClick={() => setAppliedCoupon(null)}>
            {t("removeCoupon")}
          </Button>
        </p>
      ) : (
        <div className="grid gap-2">
          <Label htmlFor="coupon">{t("coupon")}</Label>
          <div className="flex gap-2">
            <Input
              id="coupon"
              value={coupon}
              maxLength={40}
              autoComplete="off"
              autoCapitalize="characters"
              aria-invalid={applyCoupon.isError || undefined}
              aria-describedby={applyCoupon.isError ? "coupon-error" : undefined}
              onChange={(e) => {
                setCoupon(e.target.value);
                applyCoupon.reset();
              }}
              onKeyDown={(e) => {
                // Enter applies the code; it must never submit the payment at the old price.
                if (e.key === "Enter") {
                  e.preventDefault();
                  submitCoupon();
                }
              }}
            />
            <Button
              type="button"
              variant="outline"
              disabled={!coupon.trim() || applyCoupon.isPending}
              aria-busy={applyCoupon.isPending}
              onClick={submitCoupon}
            >
              {t("applyCoupon")}
            </Button>
          </div>
          {applyCoupon.isError ? (
            <p id="coupon-error" role="alert" className="text-sm text-destructive">
              {errorText(applyCoupon.error)}
            </p>
          ) : null}
        </div>
      )}

      {q.is_free ? null : rails.isPending ? (
        <Skeleton className="h-24 w-full" />
      ) : !anyRail ? (
        <p role="alert" className="text-sm text-destructive">
          {t("noRails")}
        </p>
      ) : (
        <>
          <RailPicker available={availableRails} value={rail} onChange={setRail} />
          <PayerInput id="payer" value={payer ?? user?.phone ?? ""} onChange={setPayer} />
        </>
      )}

      {pay.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(pay.error)}
        </p>
      ) : null}

      <Button type="submit" size="lg" disabled={!canPay || pay.isPending} aria-busy={pay.isPending}>
        {pay.isPending ? t("creating") : q.is_free ? t("getFree") : t("pay", { amount: money(q.total) })}
      </Button>
      <p className="flex items-start gap-2 text-xs text-muted-foreground">
        <ShieldCheck aria-hidden className="size-4 shrink-0" />
        {t("secure")}
      </p>
    </form>
  );
}
