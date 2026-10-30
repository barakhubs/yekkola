"use client";

import { success } from "@yekkola/api-client";
import { useAdminUsersIndex } from "@yekkola/api-client/endpoints/user";
import type { AdminUserResource } from "@yekkola/api-client/model";
import { formatDateTime, type Locale } from "@yekkola/i18n";
import { DataTable, dataTableColumns } from "@yekkola/ui/blocks/data-table";
import { PageHeader } from "@yekkola/ui/blocks/page-header";
import { StatusBadge } from "@yekkola/ui/blocks/status-badge";
import { Input } from "@yekkola/ui/components/input";
import { Label } from "@yekkola/ui/components/label";
import { useLocale, useTranslations } from "next-intl";
import { parseAsInteger, parseAsString, useQueryState } from "nuqs";
import { type FormEvent, useState } from "react";

import { useErrorText } from "@/features/common/errors";
import { selectClass } from "@/features/common/styles";
import { fromSort, toSort, useTableLabels } from "@/features/common/table";
import { Link } from "@/i18n/navigation";

import { ROLES, USER_STATES, USER_TONES, userStateOf } from "./status";

const col = dataTableColumns<AdminUserResource>();

/** Accounts (PRD-09 FR-04): find by phone or name; filter by status or role. Newest first. */
export function UserList() {
  const t = useTranslations("admin.users");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const labels = useTableLabels();
  const [search, setSearch] = useQueryState("q", parseAsString.withDefault(""));
  const [status, setStatus] = useQueryState("status", parseAsString.withDefault("all"));
  const [role, setRole] = useQueryState("role", parseAsString.withDefault("all"));
  const [sort, setSort] = useQueryState("sort", parseAsString);
  const [page, setPage] = useQueryState("page", parseAsInteger.withDefault(1));
  const [draft, setDraft] = useState(search);

  const users = useAdminUsersIndex(
    {
      page,
      per_page: 25,
      ...(search ? { "filter[q]": search } : {}),
      ...(status === "all" ? {} : { "filter[status]": status }),
      ...(role === "all" ? {} : { "filter[role]": role }),
      ...(sort ? { sort } : {}),
    },
    { query: { select: (r) => success(r), placeholderData: (previous) => previous } },
  );

  const columns = col.columns([
    col.accessor((row) => row.name ?? "", {
      id: "name",
      header: t("columns.name"),
      enableSorting: false,
      cell: ({ row }) => (
        <Link href={`/users/${row.original.id}`} className="font-semibold text-primary hover:underline">
          {row.original.name ?? t("noName")}
        </Link>
      ),
    }),
    col.accessor((row) => row.phone ?? "", { id: "phone", header: t("columns.phone"), enableSorting: false }),
    col.accessor((row) => row.roles.join(", "), {
      id: "roles",
      header: t("columns.roles"),
      enableSorting: false,
      cell: ({ row }) => row.original.roles.map((r) => t(`roles.${r}` as never)).join(", "),
    }),
    col.accessor("status", {
      header: t("columns.status"),
      enableSorting: false,
      cell: ({ getValue }) => {
        const value = userStateOf(getValue());
        return <StatusBadge status={value} tones={USER_TONES} label={t(`status.${value}`)} />;
      },
    }),
    col.accessor("last_seen_at", {
      header: t("columns.lastSeen"),
      cell: ({ getValue }) => {
        const at = getValue();
        return at ? formatDateTime(at, locale) : "—";
      },
    }),
    col.accessor("created_at", {
      header: t("columns.created"),
      cell: ({ getValue }) => formatDateTime(getValue(), locale),
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
          <Label htmlFor="user-search">{t("search")}</Label>
          <Input
            id="user-search"
            type="search"
            value={draft}
            onChange={(e) => setDraft(e.target.value)}
            placeholder={t("searchHint")}
          />
        </form>
        <div className="grid gap-2">
          <Label htmlFor="user-status">{t("columns.status")}</Label>
          <select
            id="user-status"
            className={selectClass}
            value={status}
            onChange={(e) => {
              void setStatus(e.target.value);
              void setPage(1);
            }}
          >
            <option value="all">{t("allStatuses")}</option>
            {USER_STATES.map((value) => (
              <option key={value} value={value}>
                {t(`status.${value}`)}
              </option>
            ))}
          </select>
        </div>
        <div className="grid gap-2">
          <Label htmlFor="user-role">{t("columns.roles")}</Label>
          <select
            id="user-role"
            className={selectClass}
            value={role}
            onChange={(e) => {
              void setRole(e.target.value);
              void setPage(1);
            }}
          >
            <option value="all">{t("allRoles")}</option>
            {ROLES.map((value) => (
              <option key={value} value={value}>
                {t(`roles.${value}`)}
              </option>
            ))}
          </select>
        </div>
      </div>

      {users.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(users.error)}
        </p>
      ) : (
        <DataTable
          columns={columns}
          data={users.data?.data ?? []}
          getRowId={(row) => row.id}
          caption={t("title")}
          sort={toSort(sort)}
          onSortChange={(next) => {
            void setSort(fromSort(next));
            void setPage(1);
          }}
          page={page}
          pageCount={users.data?.meta.last_page ?? 1}
          onPageChange={(next) => void setPage(next)}
          loading={users.isFetching}
          empty={t("empty")}
          labels={labels}
        />
      )}
    </div>
  );
}
