"use client";

import { success } from "@yekkola/api-client";
import { useMeOrdersIndex } from "@yekkola/api-client/endpoints/order";
import { formatDate, formatMoney, type Locale } from "@yekkola/i18n";
import { EmptyState } from "@yekkola/ui/blocks/empty-state";
import { PageHeader } from "@yekkola/ui/blocks/page-header";
import { type StatusTone, StatusBadge } from "@yekkola/ui/blocks/status-badge";
import { Button } from "@yekkola/ui/components/button";
import { Skeleton } from "@yekkola/ui/components/skeleton";
import { Receipt } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { parseAsInteger, useQueryState } from "nuqs";
import { useState } from "react";

import { Link } from "@/i18n/navigation";

import { canStillPay } from "../order-state";
import { receiptUrl } from "./payment-status";

type OrderStatus =
  | "pending"
  | "awaiting_payment"
  | "paid"
  | "expired"
  | "cancelled"
  | "reversed"
  | "refunded"
  | "partially_refunded";

const TONES: Record<OrderStatus, StatusTone> = {
  pending: "neutral",
  awaiting_payment: "warning",
  paid: "success",
  expired: "neutral",
  cancelled: "neutral",
  reversed: "danger",
  refunded: "info",
  partially_refunded: "info",
};

/** Order history with status and receipt links (PRD-05 FR-17). Receipts exist once an order was paid. */
export function OrderList() {
  const t = useTranslations("web.orders");
  const pagination = useTranslations("web.pagination");
  const errors = useTranslations("errors");
  const locale = useLocale() as Locale;
  const [page, setPage] = useQueryState("page", parseAsInteger.withDefault(1));
  const orders = useMeOrdersIndex({ page, per_page: 20 }, { query: { select: (r) => success(r) } });
  const lastPage = orders.data?.meta.last_page ?? 1;
  // Listing doesn't expire stale orders; past their deadline they can't be paid, so no "continue" link.
  const [now] = useState(() => Date.now());

  return (
    <div className="mx-auto grid max-w-3xl gap-6 px-4 py-8 sm:py-12">
      <PageHeader title={t("title")} />
      {orders.isPending ? (
        <Skeleton className="h-40 w-full" />
      ) : orders.isError ? (
        <EmptyState title={errors("network")} />
      ) : orders.data.data.length === 0 ? (
        <EmptyState icon={<Receipt />} title={t("empty")} />
      ) : (
        <ul className="grid gap-3">
          {orders.data.data.map((order) => {
            const status = (order.status in TONES ? order.status : "pending") as OrderStatus;
            const hasReceipt = order.paid_at !== null;
            const open = canStillPay(order, now);

            return (
              <li key={order.id} className="grid gap-2 rounded-xl border p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <span className="font-bold">{t("number", { number: order.number })}</span>
                  <StatusBadge status={status} tones={TONES} label={t(`status.${status}`)} />
                </div>
                <ul className="text-sm">
                  {order.items.map((item) => (
                    <li key={item.course_id}>{item.title}</li>
                  ))}
                </ul>
                <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                  <span className="text-muted-foreground">
                    {formatDate(order.created_at, locale)} · {formatMoney(order.total, locale)}
                  </span>
                  <span className="flex gap-2">
                    {open ? (
                      <Button asChild size="sm">
                        <Link href={`/checkout/${order.id}`}>{t("continuePayment")}</Link>
                      </Button>
                    ) : null}
                    {hasReceipt ? (
                      <Button asChild size="sm" variant="outline">
                        <a href={receiptUrl(order.id)}>{t("receipt")}</a>
                      </Button>
                    ) : null}
                  </span>
                </div>
              </li>
            );
          })}
        </ul>
      )}
      {lastPage > 1 ? (
        <nav aria-label={pagination("label")} className="flex items-center justify-between gap-4">
          <Button variant="outline" disabled={page <= 1} onClick={() => setPage(page - 1)}>
            {pagination("previous")}
          </Button>
          <span className="text-sm text-muted-foreground">
            {pagination("page", { page, total: lastPage })}
          </span>
          <Button variant="outline" disabled={page >= lastPage} onClick={() => setPage(page + 1)}>
            {pagination("next")}
          </Button>
        </nav>
      ) : null}
    </div>
  );
}
