"use client";

import { success } from "@yekkola/api-client";
import { useAdminRefundsIndex } from "@yekkola/api-client/endpoints/refund";
import type { AdminRefundResource } from "@yekkola/api-client/model";
import { formatDateTime, formatMoney, type Locale } from "@yekkola/i18n";
import { DataTable, dataTableColumns } from "@yekkola/ui/blocks/data-table";
import { PageHeader } from "@yekkola/ui/blocks/page-header";
import { StatusBadge } from "@yekkola/ui/blocks/status-badge";
import { Badge } from "@yekkola/ui/components/badge";
import { Label } from "@yekkola/ui/components/label";
import { useLocale, useTranslations } from "next-intl";
import { parseAsInteger, parseAsString, useQueryState } from "nuqs";

import { useErrorText } from "@/features/common/errors";
import { selectClass } from "@/features/common/styles";
import { fromSort, toSort, useTableLabels } from "@/features/common/table";
import { Link } from "@/i18n/navigation";

import { REFUND_STATES, REFUND_TONES, refundStateOf } from "./status";

const col = dataTableColumns<AdminRefundResource>();

/**
 * Refund requests (PRD-09 FR-12, PRD-05 FR-16): requests waiting for a decision first; failed payouts and flags
 * (paid twice, amount mismatch…) stand out. Filter and page live in the URL.
 */
export function RefundQueue() {
  const t = useTranslations("admin.refunds");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const labels = useTableLabels();
  const [status, setStatus] = useQueryState("status", parseAsString.withDefault("requested"));
  const [sort, setSort] = useQueryState("sort", parseAsString);
  const [page, setPage] = useQueryState("page", parseAsInteger.withDefault(1));

  const refunds = useAdminRefundsIndex(
    {
      page,
      per_page: 25,
      ...(status === "all" ? {} : { "filter[status]": status }),
      ...(sort ? { sort } : {}),
    },
    { query: { select: (r) => success(r), placeholderData: (previous) => previous } },
  );

  const columns = col.columns([
    col.accessor("order_number", {
      header: t("columns.order"),
      enableSorting: false,
      cell: ({ row }) => (
        <Link
          href={`/finance/refunds/${row.original.id}`}
          className="font-semibold text-primary hover:underline"
        >
          {row.original.order_number}
        </Link>
      ),
    }),
    col.accessor((row) => row.course ?? t("unapplied"), {
      id: "course",
      header: t("columns.course"),
      enableSorting: false,
    }),
    col.accessor((row) => row.amount.amount_minor, {
      id: "amount_minor",
      header: t("columns.amount"),
      cell: ({ row }) => <span className="tabular-nums">{formatMoney(row.original.amount, locale)}</span>,
    }),
    col.accessor("status", {
      header: t("columns.status"),
      enableSorting: false,
      cell: ({ row }) => {
        const value = refundStateOf(row.original.status);
        return (
          <span className="flex flex-wrap gap-1">
            <StatusBadge status={value} tones={REFUND_TONES} label={t(`status.${value}`)} />
            {row.original.flag ? (
              <Badge variant="destructive">{t(`flags.${row.original.flag}` as never)}</Badge>
            ) : null}
          </span>
        );
      },
    }),
    col.accessor("created_at", {
      header: t("columns.requested"),
      cell: ({ getValue }) => formatDateTime(getValue(), locale),
    }),
  ]);

  return (
    <div className="mx-auto grid max-w-6xl gap-6 px-4 py-8 sm:px-6">
      <PageHeader title={t("title")} description={t("intro")} />

      <div className="grid max-w-xs gap-2">
        <Label htmlFor="refund-status">{t("filterStatus")}</Label>
        <select
          id="refund-status"
          className={selectClass}
          value={status}
          onChange={(e) => {
            void setStatus(e.target.value);
            void setPage(1);
          }}
        >
          <option value="all">{t("allStatuses")}</option>
          {REFUND_STATES.map((value) => (
            <option key={value} value={value}>
              {t(`status.${value}`)}
            </option>
          ))}
        </select>
      </div>

      {refunds.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(refunds.error)}
        </p>
      ) : (
        <DataTable
          columns={columns}
          data={refunds.data?.data ?? []}
          getRowId={(row) => row.id}
          caption={t("title")}
          sort={toSort(sort)}
          onSortChange={(next) => {
            void setSort(fromSort(next));
            void setPage(1);
          }}
          page={page}
          pageCount={refunds.data?.meta.last_page ?? 1}
          onPageChange={(next) => void setPage(next)}
          loading={refunds.isFetching}
          empty={t("empty")}
          labels={labels}
        />
      )}
    </div>
  );
}
