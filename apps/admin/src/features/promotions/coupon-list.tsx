"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { success } from "@yekkola/api-client";
import {
  adminCouponsUpdate,
  getAdminCouponsIndexQueryKey,
  useAdminCouponsIndex,
} from "@yekkola/api-client/endpoints/coupon";
import type { AdminCouponsIndex200DataItem } from "@yekkola/api-client/model";
import { formatDate, formatMoney, type Locale } from "@yekkola/i18n";
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

import { useErrorText } from "@/features/common/errors";
import { selectClass } from "@/features/common/styles";
import { fromSort, toSort, useTableLabels } from "@/features/common/table";

import { CouponDialog } from "./coupon-dialog";
import { COUPON_TONES } from "./shared";

const col = dataTableColumns<AdminCouponsIndex200DataItem>();
const SCOPES = ["platform", "professor", "course"] as const;

/**
 * Coupons (PRD-09 FR-13): every code — the platform's and professors' — with what it applies to, who funds
 * it, its validity and paid uses; create platform-funded codes, change limits, deactivate.
 */
export function CouponList() {
  const t = useTranslations("admin.coupons");
  const common = useTranslations("common");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const labels = useTableLabels();
  const queryClient = useQueryClient();
  const [search, setSearch] = useQueryState("q", parseAsString.withDefault(""));
  const [scope, setScope] = useQueryState("scope", parseAsString.withDefault("all"));
  const [active, setActive] = useQueryState("active", parseAsString.withDefault("all"));
  const [sort, setSort] = useQueryState("sort", parseAsString);
  const [page, setPage] = useQueryState("page", parseAsInteger.withDefault(1));
  const [draft, setDraft] = useState(search);

  const coupons = useAdminCouponsIndex(
    {
      page,
      per_page: 25,
      ...(search ? { "filter[code]": search } : {}),
      ...(scope !== "all" ? { "filter[scope]": scope } : {}),
      ...(active !== "all" ? { "filter[is_active]": active } : {}),
      ...(sort ? { sort } : {}),
    },
    { query: { select: (r) => success(r), placeholderData: (previous) => previous } },
  );

  const toggle = useMutation({
    mutationFn: ({ id, isActive }: { id: string; isActive: boolean }) =>
      adminCouponsUpdate(id, { is_active: isActive }),
    onSettled: () => queryClient.invalidateQueries({ queryKey: getAdminCouponsIndexQueryKey() }),
  });

  const validity = (c: AdminCouponsIndex200DataItem) => {
    const start = c.starts_at ? formatDate(c.starts_at, locale) : null;
    const end = c.ends_at ? formatDate(c.ends_at, locale) : null;
    if (start && end) return t("between", { start, end });
    if (start) return t("from", { date: start });
    if (end) return t("until", { date: end });
    return t("always");
  };

  const columns = col.columns([
    col.accessor("code", {
      header: t("columns.code"),
      cell: ({ row }) => {
        const c = row.original;
        return (
          <span className="grid">
            <span className="font-mono font-semibold">{c.code}</span>
            <span className="text-xs text-muted-foreground">
              {c.discount_type === "fixed" && c.currency
                ? t("amountOff", {
                    amount: formatMoney({ amount_minor: c.value, currency: c.currency }, locale),
                  })
                : t("percentOff", { value: c.value })}
              {" · "}
              {t("fundedBy", { funder: t(`funders.${c.funded_by as "platform"}`) })}
            </span>
          </span>
        );
      },
    }),
    col.display({
      id: "applies_to",
      header: t("columns.appliesTo"),
      cell: ({ row }) => {
        const c = row.original;
        const name =
          c.scope === "course"
            ? (c.course?.title ?? "—")
            : c.scope === "professor"
              ? c.professor?.display_name
              : null;
        return (
          <span className="grid">
            <span>{t(`scopes.${c.scope as "platform"}`)}</span>
            {name ? <span className="text-xs text-muted-foreground">{name}</span> : null}
            {c.scope === "course" && c.professor ? (
              <span className="text-xs text-muted-foreground">{c.professor.display_name}</span>
            ) : null}
          </span>
        );
      },
    }),
    col.display({ id: "validity", header: t("columns.validity"), cell: ({ row }) => validity(row.original) }),
    col.display({
      id: "uses",
      header: t("columns.uses"),
      cell: ({ row }) => {
        const c = row.original;
        return (
          <span className="grid">
            <span>
              {c.max_redemptions === null ? c.uses : t("usesOf", { uses: c.uses, max: c.max_redemptions })}
            </span>
            {c.per_user_limit !== null ? (
              <span className="text-xs text-muted-foreground">
                {t("perUser", { limit: c.per_user_limit })}
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
        const state = row.original.state;
        return <StatusBadge status={state} tones={COUPON_TONES} label={t(`state.${state}`)} />;
      },
    }),
    col.display({
      id: "actions",
      header: () => <span className="sr-only">{t("columns.actions")}</span>,
      cell: ({ row }) => {
        const c = row.original;
        return (
          <span className="flex flex-wrap justify-end gap-1">
            <CouponDialog
              coupon={c}
              trigger={
                <Button size="sm" variant="ghost">
                  {t("edit")}
                </Button>
              }
            />
            {c.is_active ? (
              <ConfirmDialog
                trigger={
                  <Button size="sm" variant="outline">
                    {t("deactivate")}
                  </Button>
                }
                title={t("deactivateTitle", { code: c.code })}
                description={t("deactivateHint")}
                confirmLabel={t("deactivate")}
                cancelLabel={common("cancel")}
                onConfirm={async () => {
                  await toggle.mutateAsync({ id: c.id, isActive: false });
                }}
                error={toggle.isError && toggle.variables?.id === c.id ? errorText(toggle.error) : undefined}
                onOpenChange={(open) => {
                  if (open) toggle.reset();
                }}
              />
            ) : (
              <Button
                size="sm"
                variant="outline"
                disabled={toggle.isPending}
                onClick={() => toggle.mutate({ id: c.id, isActive: true })}
              >
                {t("activate")}
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
          <CouponDialog
            trigger={
              <Button>
                <Plus aria-hidden />
                {t("new")}
              </Button>
            }
          />
        }
      />

      <div className="grid gap-4 sm:grid-cols-4">
        <form
          role="search"
          className="grid gap-2 sm:col-span-2"
          onSubmit={(e: FormEvent) => {
            e.preventDefault();
            void setSearch(draft.trim() || null);
            void setPage(1);
          }}
        >
          <Label htmlFor="coupon-search">{t("search")}</Label>
          <Input id="coupon-search" type="search" value={draft} onChange={(e) => setDraft(e.target.value)} />
        </form>
        <div className="grid gap-2">
          <Label htmlFor="coupon-scope-filter">{t("form.scope")}</Label>
          <select
            id="coupon-scope-filter"
            className={selectClass}
            value={scope}
            onChange={(e) => {
              void setScope(e.target.value);
              void setPage(1);
            }}
          >
            <option value="all">{t("allScopes")}</option>
            {SCOPES.map((value) => (
              <option key={value} value={value}>
                {t(`scopes.${value}`)}
              </option>
            ))}
          </select>
        </div>
        <div className="grid gap-2">
          <Label htmlFor="coupon-active-filter">{t("columns.status")}</Label>
          <select
            id="coupon-active-filter"
            className={selectClass}
            value={active}
            onChange={(e) => {
              void setActive(e.target.value);
              void setPage(1);
            }}
          >
            <option value="all">{t("allStates")}</option>
            <option value="1">{t("activeOnly")}</option>
            <option value="0">{t("inactiveOnly")}</option>
          </select>
        </div>
      </div>

      {toggle.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(toggle.error)}
        </p>
      ) : null}

      {coupons.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(coupons.error)}
        </p>
      ) : (
        <DataTable
          columns={columns}
          data={coupons.data?.data ?? []}
          getRowId={(row) => row.id}
          caption={t("title")}
          sort={toSort(sort)}
          onSortChange={(next) => {
            void setSort(fromSort(next));
            void setPage(1);
          }}
          page={page}
          pageCount={coupons.data?.meta.last_page ?? 1}
          onPageChange={(next) => void setPage(next)}
          loading={coupons.isFetching}
          empty={t("empty")}
          labels={labels}
        />
      )}
    </div>
  );
}
