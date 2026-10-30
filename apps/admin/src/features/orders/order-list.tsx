"use client";

import { success } from "@yekkola/api-client";
import { useAdminOrdersIndex } from "@yekkola/api-client/endpoints/order";
import type { AdminOrderResource } from "@yekkola/api-client/model";
import { formatDateTime, formatMoney, type Locale } from "@yekkola/i18n";
import { DataTable, dataTableColumns } from "@yekkola/ui/blocks/data-table";
import { PageHeader } from "@yekkola/ui/blocks/page-header";
import { StatusBadge } from "@yekkola/ui/blocks/status-badge";
import { Badge } from "@yekkola/ui/components/badge";
import { Input } from "@yekkola/ui/components/input";
import { Label } from "@yekkola/ui/components/label";
import { useLocale, useTranslations } from "next-intl";
import { parseAsInteger, parseAsString, useQueryState } from "nuqs";
import { type FormEvent, useState } from "react";

import { useErrorText } from "@/features/common/errors";
import { selectClass } from "@/features/common/styles";
import { fromSort, toSort, useTableLabels } from "@/features/common/table";
import { Link } from "@/i18n/navigation";

import { ORDER_STATES, ORDER_TONES, orderStateOf } from "./status";

const col = dataTableColumns<AdminOrderResource>();

/**
 * Orders (PRD-09 FR-11): newest first; filter by status, flag, stuck payments, or find one by number or phone.
 * Filters and page live in the URL.
 */
export function OrderList() {
  const t = useTranslations("admin.orders");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const labels = useTableLabels();
  const [status, setStatus] = useQueryState("status", parseAsString.withDefault("all"));
  const [only, setOnly] = useQueryState("only", parseAsString.withDefault("all"));
  const [search, setSearch] = useQueryState("q", parseAsString.withDefault(""));
  const [sort, setSort] = useQueryState("sort", parseAsString);
  const [page, setPage] = useQueryState("page", parseAsInteger.withDefault(1));
  const [draft, setDraft] = useState(search);

  // A search is an order number (letters/digits with a dash) or a phone number.
  const isPhone = /^[+\d\s]{8,}$/.test(search);
  const orders = useAdminOrdersIndex(
    {
      page,
      per_page: 25,
      ...(status === "all" ? {} : { "filter[status]": status }),
      ...(only === "stuck" ? { "filter[stuck]": "1" } : only === "flagged" ? { "filter[flag]": "any" } : {}),
      ...(search ? (isPhone ? { "filter[phone]": search } : { "filter[number]": search }) : {}),
      ...(sort ? { sort } : {}),
    },
    { query: { select: (r) => success(r), placeholderData: (previous) => previous } },
  );

  const columns = col.columns([
    col.accessor("number", {
      header: t("columns.number"),
      enableSorting: false,
      cell: ({ row }) => (
        <Link href={`/orders/${row.original.id}`} className="font-semibold text-primary hover:underline">
          {row.original.number}
        </Link>
      ),
    }),
    col.accessor((row) => row.student.name ?? "", {
      id: "student",
      header: t("columns.student"),
      enableSorting: false,
      cell: ({ row }) => (
        <span className="grid">
          <span>{row.original.student.name ?? "—"}</span>
          <span className="text-xs text-muted-foreground">{row.original.student.phone}</span>
        </span>
      ),
    }),
    col.accessor((row) => row.courses.join(", "), {
      id: "courses",
      header: t("columns.courses"),
      enableSorting: false,
    }),
    col.accessor((row) => row.total.amount_minor, {
      id: "total_minor",
      header: t("columns.total"),
      cell: ({ row }) => <span className="tabular-nums">{formatMoney(row.original.total, locale)}</span>,
    }),
    col.accessor("status", {
      header: t("columns.status"),
      enableSorting: false,
      cell: ({ row }) => {
        const value = orderStateOf(row.original.status);
        return (
          <span className="flex flex-wrap gap-1">
            <StatusBadge status={value} tones={ORDER_TONES} label={t(`status.${value}`)} />
            {row.original.flag ? (
              <Badge variant="destructive">{t(`flags.${row.original.flag}` as never)}</Badge>
            ) : null}
          </span>
        );
      },
    }),
    col.accessor("created_at", {
      header: t("columns.created"),
      cell: ({ getValue }) => <span className="whitespace-nowrap">{formatDateTime(getValue(), locale)}</span>,
    }),
  ]);

  return (
    <div className="mx-auto grid max-w-6xl gap-6 px-4 py-8 sm:px-6">
      <PageHeader title={t("title")} description={t("intro")} />

      <div className="grid gap-4 sm:grid-cols-3">
        <form
          role="search"
          className="grid gap-2"
          onSubmit={(e: FormEvent) => {
            e.preventDefault();
            void setSearch(draft.trim() || null);
            void setPage(1);
          }}
        >
          <Label htmlFor="order-search">{t("search")}</Label>
          <Input
            id="order-search"
            type="search"
            value={draft}
            onChange={(e) => setDraft(e.target.value)}
            placeholder={t("searchHint")}
          />
        </form>
        <div className="grid gap-2">
          <Label htmlFor="order-status">{t("columns.status")}</Label>
          <select
            id="order-status"
            className={selectClass}
            value={status}
            onChange={(e) => {
              void setStatus(e.target.value);
              void setPage(1);
            }}
          >
            <option value="all">{t("allStatuses")}</option>
            {ORDER_STATES.map((value) => (
              <option key={value} value={value}>
                {t(`status.${value}`)}
              </option>
            ))}
          </select>
        </div>
        <div className="grid gap-2">
          <Label htmlFor="order-only">{t("show")}</Label>
          <select
            id="order-only"
            className={selectClass}
            value={only}
            onChange={(e) => {
              void setOnly(e.target.value);
              void setPage(1);
            }}
          >
            <option value="all">{t("showAll")}</option>
            <option value="stuck">{t("showStuck")}</option>
            <option value="flagged">{t("showFlagged")}</option>
          </select>
        </div>
      </div>

      {orders.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(orders.error)}
        </p>
      ) : (
        <DataTable
          columns={columns}
          data={orders.data?.data ?? []}
          getRowId={(row) => row.id}
          caption={t("title")}
          sort={toSort(sort)}
          onSortChange={(next) => {
            void setSort(fromSort(next));
            void setPage(1);
          }}
          page={page}
          pageCount={orders.data?.meta.last_page ?? 1}
          onPageChange={(next) => void setPage(next)}
          loading={orders.isFetching}
          empty={t("empty")}
          labels={labels}
        />
      )}
    </div>
  );
}
