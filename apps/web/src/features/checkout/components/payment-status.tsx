"use client";

import { useMutation, useQuery } from "@tanstack/react-query";
import { ApiError, apiUrl, success } from "@yekkola/api-client";
import { useCheckoutRails } from "@yekkola/api-client/endpoints/checkout";
import { ordersPay, ordersShow } from "@yekkola/api-client/endpoints/order";
import type { Currency, MobileMoneyRail, OrderResource } from "@yekkola/api-client/model";
import { formatMoney, formatPhone, type Locale } from "@yekkola/i18n";
import { EmptyState } from "@yekkola/ui/blocks/empty-state";
import { Button } from "@yekkola/ui/components/button";
import { Skeleton } from "@yekkola/ui/components/skeleton";
import { cn } from "@yekkola/ui/lib/utils";
import { CheckCircle2, Clock, Smartphone, XCircle } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { type FormEvent, useEffect, useRef, useState } from "react";

import { useSession } from "@/features/auth/hooks/use-session";
import { Link } from "@/i18n/navigation";

import { payKeyScope, withIdempotencyKey } from "../idempotency";
import { stateOf } from "../order-state";
import { msisdnOf, PayerInput } from "./payer-input";
import { RailPicker } from "./rail-picker";

const TERMINAL = new Set(["paid", "expired", "cancelled", "reversed", "refunded", "partially_refunded"]);
/** Polling backoff while waiting for the operator (PRD-05 FR-06): quick at first, then gentler. */
const BACKOFF_MS = [2_000, 2_000, 3_000, 3_000, 5_000, 5_000, 8_000];

/**
 * After the payment request (PRD-05 FR-06–FR-09): "approve on your phone", then success or a clear reason
 * with a retry on the same order until it expires. Success is shown only once the API confirms it.
 */
export function PaymentStatus({ orderId }: { orderId: string }) {
  const t = useTranslations("web.checkout");
  const errors = useTranslations("errors");
  const locale = useLocale() as Locale;
  const polls = useRef(0);
  const heading = useRef<HTMLHeadingElement>(null);

  const order = useQuery({
    queryKey: ["order", orderId],
    queryFn: async () => success(await ordersShow(orderId)).data,
    refetchInterval: (query) => {
      const status = query.state.data?.status;
      const latest = query.state.data?.payments[0]?.status;
      if (!status || TERMINAL.has(status) || !latest || latest === "failed" || latest === "expired")
        return false;
      polls.current += 1;
      return BACKOFF_MS[Math.min(polls.current, BACKOFF_MS.length - 1)] ?? 8_000;
    },
    refetchIntervalInBackground: true,
    retry: (failures, error) => !(error instanceof ApiError && error.status < 500) && failures < 3,
  });

  const state = order.data ? stateOf(order.data) : null;
  const attempt = order.data?.payments[0]?.id;
  // Each new screen (waiting, result, retry) — and each new attempt, even one that fails straight away — is
  // announced by moving focus to its heading.
  useEffect(() => {
    if (state) heading.current?.focus();
  }, [state, attempt]);
  const refresh = () => {
    polls.current = 0;
    void order.refetch();
  };

  if (order.isPending) {
    return (
      <div className="mx-auto grid max-w-xl gap-4 px-4 py-10">
        <Skeleton className="h-8 w-60" />
        <Skeleton className="h-32 w-full" />
      </div>
    );
  }

  if (order.isError || !order.data || !state) {
    const missing = order.error instanceof ApiError && order.error.status === 404;
    return (
      <div className="mx-auto max-w-xl px-4 py-16">
        <EmptyState title={missing ? t("unknownOrder") : errors("network")} />
      </div>
    );
  }

  const o = order.data;
  const payment = o.payments[0];
  const amount = formatMoney(o.total, locale);

  return (
    <div className="mx-auto grid max-w-xl gap-6 px-4 py-10 sm:py-14">
      {state === "waiting" ? (
        <section className="grid justify-items-center gap-4 rounded-xl border p-6 text-center">
          <Smartphone aria-hidden className="size-12 animate-pulse text-primary" />
          <h1 ref={heading} tabIndex={-1} className="text-2xl font-extrabold focus:outline-none">
            {t("waitingTitle")}
          </h1>
          <p>
            {t("waitingBody", {
              operator: payment ? t(`rails.${payment.rail as MobileMoneyRail}`) : "",
              amount,
              phone: payment ? formatPhone(payment.payer) : "",
            })}
          </p>
          <p className="text-sm text-muted-foreground">{t("waitingHint")}</p>
          <ExpiresIn expiresAt={o.expires_at} onExpired={refresh} />
          <p role="status" className="text-xs text-muted-foreground">
            {t("checking")}
          </p>
        </section>
      ) : null}

      {state === "paid" ? (
        <section className="grid justify-items-center gap-4 rounded-xl border-2 border-success p-6 text-center">
          <CheckCircle2 aria-hidden className="size-12 text-success" />
          <h1 ref={heading} tabIndex={-1} className="text-2xl font-extrabold focus:outline-none">
            {t("paidTitle")}
          </h1>
          <p>{o.is_gift ? t("paidGiftBody") : t("paidBody")}</p>
          <div className="flex flex-wrap justify-center gap-2">
            {o.items[0] && !o.is_gift ? (
              <Button asChild size="lg">
                <Link href={`/learn/${o.items[0].course_id}`}>{t("startLearning")}</Link>
              </Button>
            ) : null}
            <Button asChild variant="outline" size="lg">
              <a href={receiptUrl(o.id)}>{t("receipt")}</a>
            </Button>
          </div>
        </section>
      ) : null}

      {state === "failed" || state === "choose" ? (
        <section
          className={cn(
            "grid gap-4 rounded-xl border p-6",
            state === "failed" && "border-2 border-destructive",
          )}
        >
          <div className="flex items-center gap-3">
            {state === "failed" ? (
              <XCircle aria-hidden className="size-8 shrink-0 text-destructive" />
            ) : (
              <Smartphone aria-hidden className="size-8 shrink-0 text-primary" />
            )}
            <h1 ref={heading} tabIndex={-1} className="text-xl font-extrabold focus:outline-none">
              {state === "failed" ? t("failedTitle") : t("chooseTitle")}
            </h1>
          </div>
          {state === "failed" ? (
            <>
              {payment?.failure_reason ? <p>{payment.failure_reason}</p> : null}
              <p className="text-sm text-muted-foreground">{t("retryOther")}</p>
            </>
          ) : null}
          <RetryPayment order={o} onSent={refresh} />
          <ExpiresIn expiresAt={o.expires_at} onExpired={refresh} />
        </section>
      ) : null}

      {state === "expired" ? (
        <EmptyState
          icon={<Clock />}
          title={t("expiredTitle")}
          description={t("expiredBody")}
          action={
            o.items[0] ? (
              <Button asChild>
                <Link href={`/checkout?course=${o.items[0].course_id}`}>{t("startOver")}</Link>
              </Button>
            ) : null
          }
        />
      ) : null}
    </div>
  );
}

/** The PDF receipt, served by the API with the session cookie (PRD-05 FR-15). */
export function receiptUrl(orderId: string): string {
  return apiUrl(`/api/v1/orders/${orderId}/receipt.pdf`);
}

/**
 * Same order, another try: same or another operator and number (PRD-05 FR-09). A try the API answered gets a new
 * idempotency key; one lost on the network is sent again with the same key.
 */
function RetryPayment({ order, onSent }: { order: OrderResource; onSent: () => void }) {
  const t = useTranslations("web.checkout");
  const errors = useTranslations("errors");
  const locale = useLocale() as Locale;
  const { user } = useSession();
  const last = order.payments[0];
  const [rail, setRail] = useState<MobileMoneyRail | null>(
    (last?.rail as MobileMoneyRail | undefined) ?? null,
  );
  // The last payer comes back masked, so the number is typed again (the account's own by default).
  const [payer, setPayer] = useState<string | null>(null);
  // Operators can be paused at any time (PRD-05 FR-19): ask again for this order's currency.
  const rails = useCheckoutRails(
    { currency: order.currency as Currency },
    { query: { select: (r) => success(r).data } },
  );

  const retry = useMutation({
    mutationFn: async (chosen: MobileMoneyRail) =>
      withIdempotencyKey(payKeyScope(order), async (key) =>
        ordersPay(
          order.id,
          { rail: chosen, msisdn: msisdnOf(payer ?? user?.phone ?? "") },
          { headers: { "Idempotency-Key": key } },
        ),
      ),
    onSuccess: onSent,
  });

  return (
    <form
      className="grid gap-4"
      onSubmit={(e: FormEvent) => {
        e.preventDefault();
        if (rail && !retry.isPending) retry.mutate(rail);
      }}
    >
      <RailPicker available={rails.data ?? {}} value={rail} onChange={setRail} />
      <PayerInput id="retry-payer" value={payer ?? user?.phone ?? ""} onChange={setPayer} />
      {retry.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {retry.error instanceof ApiError ? (retry.error.title ?? errors("generic")) : errors("network")}
        </p>
      ) : null}
      <div>
        <Button
          type="submit"
          disabled={!rail || rails.data?.[rail] !== true || retry.isPending}
          aria-busy={retry.isPending}
        >
          {last ? t("retry") : t("pay", { amount: formatMoney(order.total, locale) })}
        </Button>
      </div>
    </form>
  );
}

/** Time left before the order expires (order payment timeout setting). At zero the API has the last word. */
function ExpiresIn({ expiresAt, onExpired }: { expiresAt: string; onExpired: () => void }) {
  const t = useTranslations("web.checkout");
  const [now, setNow] = useState(() => Date.now());
  const notify = useRef(onExpired);

  useEffect(() => {
    notify.current = onExpired;
  });

  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(timer);
  }, []);

  const left = Math.max(0, Math.floor((new Date(expiresAt).getTime() - now) / 1000));
  const expired = left === 0;
  useEffect(() => {
    if (expired) notify.current();
  }, [expired]);

  const time = `${Math.floor(left / 60)}:${String(left % 60).padStart(2, "0")}`;

  return <p className="text-sm font-semibold tabular-nums">{t("expiresIn", { time })}</p>;
}
