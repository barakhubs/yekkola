"use client";

import { useQueryClient } from "@tanstack/react-query";
import { success } from "@yekkola/api-client";
import { getMeOrdersIndexQueryKey, useMeOrdersIndex } from "@yekkola/api-client/endpoints/order";
import { ordersRefundRequestsStore } from "@yekkola/api-client/endpoints/refund";
import type { OrderResource } from "@yekkola/api-client/model";
import { formatDate, formatMoney, type Locale } from "@yekkola/i18n";
import { ConfirmDialog } from "@yekkola/ui/blocks/confirm-dialog";
import { EmptyState } from "@yekkola/ui/blocks/empty-state";
import { PageHeader } from "@yekkola/ui/blocks/page-header";
import { type StatusTone, StatusBadge } from "@yekkola/ui/blocks/status-badge";
import { Button } from "@yekkola/ui/components/button";
import { Skeleton } from "@yekkola/ui/components/skeleton";
import { Receipt } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { parseAsInteger, useQueryState } from "nuqs";
import { useState } from "react";

import { useErrorText } from "@/features/studio/hooks";
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
                <ul className="grid gap-1 text-sm">
                  {order.items.map((item) => (
                    <li key={item.id} className="flex flex-wrap items-center justify-between gap-2">
                      <span>{item.title}</span>
                      <RefundAction order={order} item={item} />
                    </li>
                  ))}
                </ul>
                {order.is_gift ? <p className="text-xs text-muted-foreground">{t("gift")}</p> : null}
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

type RefundState =
  "requested" | "approved" | "rejected" | "processing" | "completed" | "failed" | "cancelled";

const REFUND_TONES: Record<RefundState, StatusTone> = {
  requested: "warning",
  approved: "info",
  rejected: "neutral",
  processing: "info",
  completed: "success",
  failed: "danger",
  cancelled: "neutral",
};

/**
 * A course's refund: its latest request, or the request button when the API says one would be accepted (refund
 * window, progress limit — PRD-05 FR-16). Staff decide; the money goes back to the number that paid.
 */
function RefundAction({ order, item }: { order: OrderResource; item: OrderResource["items"][number] }) {
  const t = useTranslations("web.orders");
  const errorText = useErrorText();
  const queryClient = useQueryClient();
  const [error, setError] = useState<unknown>(null);
  const status = item.refund.status as RefundState | null;
  const known = status !== null && status in REFUND_TONES;

  return (
    <span className="flex flex-wrap items-center gap-2">
      {known ? <StatusBadge status={status} tones={REFUND_TONES} label={t(`refund.${status}`)} /> : null}
      {item.refund.can_request ? (
        <ConfirmDialog
          trigger={
            <Button size="sm" variant="ghost">
              {t("refund.request")}
            </Button>
          }
          title={t("refund.title", { course: item.title })}
          description={t("refund.body")}
          reason={{ label: t("refund.reason"), minLength: 3 }}
          confirmLabel={t("refund.send")}
          cancelLabel={t("refund.cancel")}
          error={error ? errorText(error) : undefined}
          onOpenChange={() => setError(null)}
          onConfirm={async (reason) => {
            try {
              await ordersRefundRequestsStore(order.id, { order_item_id: item.id, reason });
            } catch (e) {
              setError(e);
              throw e;
            }
            await queryClient.invalidateQueries({ queryKey: getMeOrdersIndexQueryKey() });
          }}
        />
      ) : null}
    </span>
  );
}
