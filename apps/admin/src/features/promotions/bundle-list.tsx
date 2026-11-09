"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { success } from "@yekkola/api-client";
import {
  adminBundlesUpdate,
  getAdminBundlesIndexQueryKey,
  useAdminBundlesIndex,
} from "@yekkola/api-client/endpoints/bundle";
import type { AdminBundlesIndex200DataItem } from "@yekkola/api-client/model";
import { formatMoney, type Locale } from "@yekkola/i18n";
import { ConfirmDialog } from "@yekkola/ui/blocks/confirm-dialog";
import { DataTable, dataTableColumns } from "@yekkola/ui/blocks/data-table";
import { PageHeader } from "@yekkola/ui/blocks/page-header";
import { StatusBadge } from "@yekkola/ui/blocks/status-badge";
import { Button } from "@yekkola/ui/components/button";
import { Input } from "@yekkola/ui/components/input";
import { Label } from "@yekkola/ui/components/label";
import { Plus } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { parseAsInteger, parseAsString, useQueryState } from "nuqs";
import { type FormEvent, useState } from "react";

import { selectClass } from "@/features/common/styles";
import { fromSort, toSort, useTableLabels } from "@/features/common/table";

import { BundleDialog } from "./bundle-dialog";
import { BUNDLE_STATES, BUNDLE_TONES, bundleStateOf, useFormErrorText } from "./shared";

const col = dataTableColumns<AdminBundlesIndex200DataItem>();

/**
 * Bundles (PRD-09 FR-13): platform bundles and every professor's, with their courses and saving; create
 * platform bundles, edit any, put on sale or take off sale.
 */
export function BundleList() {
  const t = useTranslations("admin.bundles");
  const common = useTranslations("common");
  const locale = useLocale() as Locale;
  const errorText = useFormErrorText();
  const labels = useTableLabels();
  const queryClient = useQueryClient();
  const [search, setSearch] = useQueryState("q", parseAsString.withDefault(""));
  const [status, setStatus] = useQueryState("status", parseAsString.withDefault("all"));
  const [sort, setSort] = useQueryState("sort", parseAsString);
  const [page, setPage] = useQueryState("page", parseAsInteger.withDefault(1));
  const [draft, setDraft] = useState(search);

  const bundles = useAdminBundlesIndex(
    {
      page,
      per_page: 25,
      ...(search ? { "filter[title]": search } : {}),
      ...(status !== "all" ? { "filter[status]": status } : {}),
      ...(sort ? { sort } : {}),
    },
    { query: { select: (r) => success(r), placeholderData: (previous) => previous } },
  );

  // Status alone: taking a bundle off sale always works, even if its courses changed price since.
  const setBundleStatus = useMutation({
    mutationFn: ({ id, next }: { id: string; next: "active" | "draft" }) =>
      adminBundlesUpdate(id, { status: next }),
    onSettled: () => queryClient.invalidateQueries({ queryKey: getAdminBundlesIndexQueryKey() }),
  });

  const columns = col.columns([
    col.accessor("title", {
      header: t("columns.title"),
      cell: ({ row }) => (
        <span className="grid">
          <span className="font-semibold">{row.original.title}</span>
          <span className="text-xs text-muted-foreground">
            {row.original.professor?.display_name ?? t("platform")}
          </span>
        </span>
      ),
    }),
    col.display({
      id: "courses",
      header: t("columns.courses"),
      cell: ({ row }) => (
        <span className="grid">
          <span>{t("courseCount", { count: row.original.courses.length })}</span>
          <span className="line-clamp-2 text-xs text-muted-foreground">
            {row.original.courses.map((c) => c.title ?? c.slug).join(" · ")}
          </span>
        </span>
      ),
    }),
    col.accessor((row) => row.price.amount_minor, {
      id: "price_minor",
      header: t("columns.price"),
      cell: ({ row }) => {
        const { price, list_total: total } = row.original;
        const pct =
          total.amount_minor > 0 ? Math.round((1 - price.amount_minor / total.amount_minor) * 100) : 0;
        return (
          <span className="grid">
            <span className="font-semibold">{formatMoney(price, locale)}</span>
            {total.amount_minor > price.amount_minor ? (
              <span className="text-xs text-muted-foreground">
                {t("saving", { total: formatMoney(total, locale), pct })}
              </span>
            ) : null}
          </span>
        );
      },
    }),
    col.display({
      id: "status",
      header: t("columns.status"),
      cell: ({ row }) => {
        const state = bundleStateOf(row.original.status);
        return <StatusBadge status={state} tones={BUNDLE_TONES} label={t(`status.${state}`)} />;
      },
    }),
    col.display({
      id: "actions",
      header: () => <span className="sr-only">{t("columns.actions")}</span>,
      cell: ({ row }) => {
        const b = row.original;
        return (
          <span className="flex flex-wrap justify-end gap-1">
            <BundleDialog
              bundle={b}
              trigger={
                <Button size="sm" variant="ghost">
                  {t("edit")}
                </Button>
              }
            />
            {b.status === "active" ? (
              <ConfirmDialog
                trigger={
                  <Button size="sm" variant="outline">
                    {t("takeOffSale")}
                  </Button>
                }
                title={t("takeOffTitle", { title: b.title })}
                description={t("takeOffHint")}
                confirmLabel={t("takeOffSale")}
                cancelLabel={common("cancel")}
                onConfirm={async () => {
                  await setBundleStatus.mutateAsync({ id: b.id, next: "draft" });
                }}
                error={
                  setBundleStatus.isError && setBundleStatus.variables?.id === b.id
                    ? errorText(setBundleStatus.error)
                    : undefined
                }
                onOpenChange={(open) => {
                  if (open) setBundleStatus.reset();
                }}
              />
            ) : (
              <Button
                size="sm"
                variant="outline"
                disabled={setBundleStatus.isPending}
                onClick={() => setBundleStatus.mutate({ id: b.id, next: "active" })}
              >
                {t("putOnSale")}
              </Button>
            )}
          </span>
        );
      },
    }),
  ]);

  return (
    <div className="mx-auto grid max-w-6xl gap-6 px-4 py-8 sm:px-6">
      <PageHeader
        title={t("title")}
        description={t("intro")}
        actions={
          <BundleDialog
            trigger={
              <Button>
                <Plus aria-hidden />
                {t("new")}
              </Button>
            }
          />
        }
      />

      <div className="grid gap-4 sm:grid-cols-3">
        <form
          role="search"
          className="grid gap-2 sm:col-span-2"
          onSubmit={(e: FormEvent) => {
            e.preventDefault();
            void setSearch(draft.trim() || null);
            void setPage(1);
          }}
        >
          <Label htmlFor="bundle-search">{t("search")}</Label>
          <Input id="bundle-search" type="search" value={draft} onChange={(e) => setDraft(e.target.value)} />
        </form>
        <div className="grid gap-2">
          <Label htmlFor="bundle-status-filter">{t("columns.status")}</Label>
          <select
            id="bundle-status-filter"
            className={selectClass}
            value={status}
            onChange={(e) => {
              void setStatus(e.target.value);
              void setPage(1);
            }}
          >
            <option value="all">{t("allStatuses")}</option>
            {BUNDLE_STATES.map((value) => (
              <option key={value} value={value}>
                {t(`status.${value}`)}
              </option>
            ))}
          </select>
        </div>
      </div>

      {setBundleStatus.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(setBundleStatus.error)}
        </p>
      ) : null}

      {bundles.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(bundles.error)}
        </p>
      ) : (
        <DataTable
          columns={columns}
          data={bundles.data?.data ?? []}
          getRowId={(row) => row.id}
          caption={t("title")}
          sort={toSort(sort)}
          onSortChange={(next) => {
            void setSort(fromSort(next));
            void setPage(1);
          }}
          page={page}
          pageCount={bundles.data?.meta.last_page ?? 1}
          onPageChange={(next) => void setPage(next)}
          loading={bundles.isFetching}
          empty={t("empty")}
          labels={labels}
        />
      )}
    </div>
  );
}
