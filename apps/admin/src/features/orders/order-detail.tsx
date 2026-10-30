"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { success } from "@yekkola/api-client";
import {
  adminPaymentsRecheck,
  getAdminOrdersIndexQueryKey,
  getAdminOrdersShowQueryKey,
  useAdminOrdersShow,
} from "@yekkola/api-client/endpoints/order";
import type { AdminOrderDetailResource } from "@yekkola/api-client/model";
import { formatDateTime, formatMoney, type Locale } from "@yekkola/i18n";
import { EmptyState } from "@yekkola/ui/blocks/empty-state";
import { StatusBadge } from "@yekkola/ui/blocks/status-badge";
import { Badge } from "@yekkola/ui/components/badge";
import { Button } from "@yekkola/ui/components/button";
import { Skeleton } from "@yekkola/ui/components/skeleton";
import { ArrowLeft, RefreshCw } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import type { ReactNode } from "react";

import { useErrorText } from "@/features/common/errors";
import { Link } from "@/i18n/navigation";

import { ORDER_TONES, orderStateOf, PAYMENT_TONES, paymentStateOf } from "./status";

/**
 * One order (PRD-09 FR-11): who, what, the payment attempts (each re-checkable with the gateway), and a timeline of
 * everything that happened — gateway events, access granted, refunds, ledger postings — as the API recorded it.
 */
export function OrderDetail({ id }: { id: string }) {
  const t = useTranslations("admin.orders");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const queryClient = useQueryClient();
  const order = useAdminOrdersShow(id, { query: { select: (r) => success(r).data } });
  const recheck = useMutation({
    mutationFn: async (paymentId: string) => success(await adminPaymentsRecheck(paymentId)).data,
    onSuccess: (data) =>
      queryClient.setQueryData(getAdminOrdersShowQueryKey(id), {
        data: { data },
        status: 200,
        headers: new Headers(),
      }),
    onSettled: () => queryClient.invalidateQueries({ queryKey: getAdminOrdersIndexQueryKey() }),
  });

  if (order.isPending) {
    return (
      <div className="mx-auto grid max-w-5xl gap-4 px-4 py-8 sm:px-6">
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-96 w-full" />
      </div>
    );
  }
  if (order.isError) {
    return (
      <div className="mx-auto max-w-xl px-4 py-16">
        <EmptyState title={errorText(order.error)} />
      </div>
    );
  }

  const o = order.data;
  const state = orderStateOf(o.status);

  return (
    <div className="mx-auto grid max-w-5xl gap-6 px-4 py-8 sm:px-6">
      <Link
        href="/orders"
        className="flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
      >
        <ArrowLeft aria-hidden className="size-4" />
        {t("back")}
      </Link>

      <header className="flex flex-wrap items-start justify-between gap-4">
        <div className="grid gap-2">
          <h1 className="text-2xl font-extrabold">{t("detailTitle", { number: o.number })}</h1>
          <span className="flex flex-wrap gap-2">
            <StatusBadge status={state} tones={ORDER_TONES} label={t(`status.${state}`)} />
            {o.flag ? <Badge variant="destructive">{t(`flags.${o.flag}` as never)}</Badge> : null}
          </span>
        </div>
        <span className="text-2xl font-extrabold tabular-nums">{formatMoney(o.total, locale)}</span>
      </header>

      <section className="grid gap-3 rounded-xl border p-4 sm:grid-cols-2">
        <Fact label={t("columns.student")}>
          {o.student.name ?? "—"} · {o.student.phone ?? "—"}
        </Fact>
        {o.buyer ? (
          <Fact label={t("buyer")}>
            {o.buyer.name ?? "—"} · {o.buyer.phone ?? "—"}
          </Fact>
        ) : null}
        <Fact label={t("columns.created")}>{formatDateTime(o.created_at, locale)}</Fact>
        <Fact label={t("paidAt")}>{o.paid_at ? formatDateTime(o.paid_at, locale) : "—"}</Fact>
        <Fact label={t("amounts")}>
          {formatMoney(o.subtotal, locale)}
          {o.discount.amount_minor > 0 ? ` − ${formatMoney(o.discount, locale)}` : ""} ={" "}
          {formatMoney(o.total, locale)}
        </Fact>
      </section>

      <section aria-labelledby="items" className="grid gap-2">
        <h2 id="items" className="font-bold">
          {t("items")}
        </h2>
        <ul className="grid gap-1 text-sm">
          {o.items.map((item) => (
            <li key={item.id} className="flex flex-wrap justify-between gap-2 rounded-md border px-3 py-2">
              <span>{item.title}</span>
              <span className="flex items-center gap-3">
                <span className="text-xs text-muted-foreground">
                  {item.enrollment_status
                    ? t(`access.${item.enrollment_status}` as never)
                    : item.granted_at
                      ? ""
                      : t("access.none")}
                </span>
                <span className="tabular-nums">{formatMoney(item.price, locale)}</span>
              </span>
            </li>
          ))}
        </ul>
      </section>

      <section aria-labelledby="payments" className="grid gap-2">
        <h2 id="payments" className="font-bold">
          {t("payments")}
        </h2>
        {o.payments.length === 0 ? <p className="text-sm text-muted-foreground">{t("noPayments")}</p> : null}
        {o.payments.map((p) => {
          const pState = paymentStateOf(p.status);
          return (
            <div key={p.id} className="grid gap-2 rounded-xl border p-4 text-sm">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="flex flex-wrap items-center gap-2">
                  <StatusBadge status={pState} tones={PAYMENT_TONES} label={t(`paymentStatus.${pState}`)} />
                  <span>
                    {t(`rails.${p.rail}` as never)} · {p.msisdn} · {formatMoney(p.amount, locale)}
                  </span>
                  {p.flag ? <Badge variant="destructive">{t(`flags.${p.flag}` as never)}</Badge> : null}
                </span>
                {p.can.recheck ? (
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => recheck.mutate(p.id)}
                    disabled={recheck.isPending}
                    aria-busy={recheck.isPending && recheck.variables === p.id}
                  >
                    <RefreshCw aria-hidden />
                    {t("recheck")}
                  </Button>
                ) : null}
              </div>
              <p className="text-xs text-muted-foreground">
                {t("checks", { count: p.checks })}
                {p.last_checked_at
                  ? ` · ${t("lastChecked", { date: formatDateTime(p.last_checked_at, locale) })}`
                  : ""}
                {p.operator_reference ? ` · ${t("operatorRef", { ref: p.operator_reference })}` : ""}
                {p.failure_code ? ` · ${p.failure_message ?? p.failure_code}` : ""}
              </p>
              {recheck.isError && recheck.variables === p.id ? (
                <p role="alert" className="text-sm text-destructive">
                  {errorText(recheck.error)}
                </p>
              ) : null}
            </div>
          );
        })}
      </section>

      <section aria-labelledby="timeline" className="grid gap-2">
        <h2 id="timeline" className="font-bold">
          {t("timeline")}
        </h2>
        <ol className="grid gap-2 border-l pl-4 text-sm">
          {timelineOf(o, t).map((event, i) => (
            <li key={i} className="grid">
              <span className="text-xs text-muted-foreground">{formatDateTime(event.at, locale)}</span>
              <span>{event.text}</span>
            </li>
          ))}
        </ol>
      </section>
    </div>
  );
}

/** Everything the API recorded, in time order (display only: nothing is decided here). */
function timelineOf(
  o: AdminOrderDetailResource,
  t: ReturnType<typeof useTranslations<"admin.orders">>,
): { at: string; text: string }[] {
  const events = [{ at: o.created_at, text: t("events.created") }];
  for (const p of o.payments) {
    events.push({ at: p.created_at, text: t("events.paymentStarted", { rail: p.rail }) });
    for (const e of p.events) {
      events.push({
        at: e.received_at,
        text: e.error ? t("events.webhookError", { error: e.error }) : t("events.webhook"),
      });
    }
    if (p.succeeded_at) events.push({ at: p.succeeded_at, text: t("events.paymentSucceeded") });
  }
  for (const r of o.refunds)
    events.push({ at: r.created_at, text: t("events.refundRequested", { status: r.status }) });
  for (const l of o.ledger) events.push({ at: l.created_at, text: t("events.ledger", { type: l.type }) });

  return events.sort((a, b) => a.at.localeCompare(b.at));
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="grid gap-0.5">
      <span className="text-xs font-semibold text-muted-foreground">{label}</span>
      <span className="text-sm">{children}</span>
    </div>
  );
}
