"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { ApiError, success } from "@yekkola/api-client";
import {
  adminPayoutBatchesStore,
  getAdminPayoutBatchesIndexQueryKey,
  useAdminPayoutBatchesIndex,
} from "@yekkola/api-client/endpoints/payout-batch";
import type { AdminPayoutBatchesIndex200DataItem, Currency } from "@yekkola/api-client/model";
import { formatDateTime, formatMoney, type Locale } from "@yekkola/i18n";
import { DataTable, dataTableColumns } from "@yekkola/ui/blocks/data-table";
import { PageHeader } from "@yekkola/ui/blocks/page-header";
import { StatusBadge } from "@yekkola/ui/blocks/status-badge";
import { Button } from "@yekkola/ui/components/button";
import { Label } from "@yekkola/ui/components/label";
import { useLocale, useTranslations } from "next-intl";
import { parseAsInteger, parseAsString, useQueryState } from "nuqs";
import { useState } from "react";

import { useErrorText } from "@/features/common/errors";
import { selectClass } from "@/features/common/styles";
import { useTableLabels } from "@/features/common/table";
import { Link, useRouter } from "@/i18n/navigation";

import { BATCH_STATES, BATCH_TONES, batchStateOf } from "./status";

const col = dataTableColumns<AdminPayoutBatchesIndex200DataItem>();
const CURRENCIES: Currency[] = ["USD", "CDF"];

/**
 * Payout batches (PRD-08 FR-06–FR-07): newest first. A batch is built on the payout schedule; finance can also
 * build one now. An open batch (draft or being paid) blocks a new one in its currency — the API says so.
 */
export function PayoutBatches() {
  const t = useTranslations("admin.payouts");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const labels = useTableLabels();
  const router = useRouter();
  const queryClient = useQueryClient();
  const [status, setStatus] = useQueryState("status", parseAsString.withDefault("all"));
  const [page, setPage] = useQueryState("page", parseAsInteger.withDefault(1));
  const [currency, setCurrency] = useState<Currency>("USD");

  const batches = useAdminPayoutBatchesIndex(
    { page, per_page: 25, ...(status === "all" ? {} : { "filter[status]": status }) },
    { query: { select: (r) => success(r), placeholderData: (previous) => previous } },
  );
  const build = useMutation({
    mutationFn: async () => success(await adminPayoutBatchesStore({ currency })).data,
    onSuccess: (batch) => router.push(`/finance/payouts/${batch.id}`),
    onSettled: () => queryClient.invalidateQueries({ queryKey: getAdminPayoutBatchesIndexQueryKey() }),
  });
  // The batch already open in that currency: offer to go to it.
  const openBatch =
    build.error instanceof ApiError && build.error.code === "payout.batch_open"
      ? String(build.error.body.batch_id ?? "")
      : null;

  const columns = col.columns([
    col.accessor("created_at", {
      header: t("columns.created"),
      enableSorting: false,
      cell: ({ row }) => (
        <Link
          href={`/finance/payouts/${row.original.id}`}
          className="font-semibold text-primary hover:underline"
        >
          {formatDateTime(row.original.created_at, locale)}
        </Link>
      ),
    }),
    col.accessor("currency", { header: t("columns.currency"), enableSorting: false }),
    col.accessor((row) => row.total.amount_minor, {
      id: "total",
      header: t("columns.total"),
      enableSorting: false,
      cell: ({ row }) => <span className="tabular-nums">{formatMoney(row.original.total, locale)}</span>,
    }),
    col.accessor((row) => row.counts.paid ?? 0, {
      id: "lines",
      header: t("columns.lines"),
      enableSorting: false,
      cell: ({ row }) => {
        const c = row.original.counts;
        const lines = Object.entries(c).reduce(
          (sum, [key, n]) => (key === "excluded" ? sum : sum + Number(n)),
          0,
        );
        return t("linesSummary", { paid: Number(c.paid ?? 0), lines });
      },
    }),
    col.accessor("status", {
      header: t("columns.status"),
      enableSorting: false,
      cell: ({ getValue }) => {
        const value = batchStateOf(getValue());
        return <StatusBadge status={value} tones={BATCH_TONES} label={t(`status.${value}`)} />;
      },
    }),
  ]);

  return (
    <div className="mx-auto grid max-w-6xl gap-6 px-4 py-8 sm:px-6">
      <PageHeader title={t("title")} description={t("intro")} />

      <div className="flex flex-wrap items-end gap-4">
        <div className="grid w-40 gap-2">
          <Label htmlFor="batch-status">{t("filterStatus")}</Label>
          <select
            id="batch-status"
            className={selectClass}
            value={status}
            onChange={(e) => {
              void setStatus(e.target.value);
              void setPage(1);
            }}
          >
            <option value="all">{t("allStatuses")}</option>
            {BATCH_STATES.map((value) => (
              <option key={value} value={value}>
                {t(`status.${value}`)}
              </option>
            ))}
          </select>
        </div>
        <div className="ml-auto flex flex-wrap items-end gap-2">
          <div className="grid w-28 gap-2">
            <Label htmlFor="batch-currency">{t("columns.currency")}</Label>
            <select
              id="batch-currency"
              className={selectClass}
              value={currency}
              onChange={(e) => {
                setCurrency(e.target.value as Currency);
                build.reset();
              }}
            >
              {CURRENCIES.map((c) => (
                <option key={c} value={c}>
                  {c}
                </option>
              ))}
            </select>
          </div>
          <Button onClick={() => build.mutate()} disabled={build.isPending} aria-busy={build.isPending}>
            {t("build")}
          </Button>
        </div>
      </div>
      {build.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(build.error)}{" "}
          {openBatch ? (
            <Link href={`/finance/payouts/${openBatch}`} className="font-semibold underline">
              {t("openIt")}
            </Link>
          ) : null}
        </p>
      ) : null}

      {batches.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(batches.error)}
        </p>
      ) : (
        <DataTable
          columns={columns}
          data={batches.data?.data ?? []}
          getRowId={(row) => row.id}
          caption={t("title")}
          page={page}
          pageCount={batches.data?.meta.last_page ?? 1}
          onPageChange={(next) => void setPage(next)}
          loading={batches.isFetching}
          empty={t("empty")}
          labels={labels}
        />
      )}
    </div>
  );
}
