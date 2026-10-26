"use client";

import { success } from "@yekkola/api-client";
import { useAdminProfessorApplicationsIndex } from "@yekkola/api-client/endpoints/professor-application";
import type { AdminProfessorApplicationResource } from "@yekkola/api-client/model";
import { formatDateTime, type Locale } from "@yekkola/i18n";
import { DataTable, dataTableColumns, type DataTableSort } from "@yekkola/ui/blocks/data-table";
import { PageHeader } from "@yekkola/ui/blocks/page-header";
import { StatusBadge } from "@yekkola/ui/blocks/status-badge";
import { Label } from "@yekkola/ui/components/label";
import { useLocale, useTranslations } from "next-intl";
import { parseAsInteger, parseAsString, useQueryState } from "nuqs";

import { useErrorText } from "@/features/common/errors";
import { selectClass } from "@/features/common/styles";
import { APPLICATION_STATUSES, APPLICATION_TONES, applicationStatusOf } from "@/features/common/status";
import { Link } from "@/i18n/navigation";

const col = dataTableColumns<AdminProfessorApplicationResource>();

/** "-submitted_at" ⇄ { id, desc } for the API's `sort` parameter. */
function toSort(param: string | null): DataTableSort | null {
  return param ? { id: param.replace(/^-/, ""), desc: param.startsWith("-") } : null;
}

/**
 * Professor applications queue (PRD-09 FR-06, PRD-02 FR-05): oldest submissions first; filter by status. Page,
 * filter and sort live in the URL so a link to the queue keeps them.
 */
export function ApplicationQueue() {
  const t = useTranslations("admin.applications");
  const table = useTranslations("admin.table");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const [status, setStatus] = useQueryState("status", parseAsString.withDefault("submitted"));
  const [sort, setSort] = useQueryState("sort", parseAsString);
  const [page, setPage] = useQueryState("page", parseAsInteger.withDefault(1));

  const applications = useAdminProfessorApplicationsIndex(
    {
      page,
      per_page: 25,
      ...(status === "all" ? {} : { "filter[status]": status }),
      ...(sort ? { sort } : {}),
    },
    { query: { select: (r) => success(r), placeholderData: (previous) => previous } },
  );

  const columns = col.columns([
    col.accessor((row) => row.full_name ?? row.applicant.name ?? "", {
      id: "name",
      header: t("columns.name"),
      enableSorting: false,
      cell: ({ row }) => (
        <Link
          href={`/applications/${row.original.id}`}
          className="font-semibold text-primary hover:underline"
        >
          {row.original.full_name ?? row.original.applicant.name ?? t("unnamed")}
        </Link>
      ),
    }),
    col.accessor((row) => row.subjects.join(", "), {
      id: "subjects",
      header: t("columns.subjects"),
      enableSorting: false,
    }),
    col.accessor("years_experience", {
      header: t("columns.experience"),
      enableSorting: false,
      cell: ({ getValue }) => {
        const years = getValue();
        return years === null ? "—" : t("years", { count: years });
      },
    }),
    col.accessor("status", {
      header: t("columns.status"),
      enableSorting: false,
      cell: ({ getValue }) => {
        const value = applicationStatusOf(getValue());
        return <StatusBadge status={value} tones={APPLICATION_TONES} label={t(`status.${value}`)} />;
      },
    }),
    col.accessor("submitted_at", {
      header: t("columns.submitted"),
      cell: ({ getValue }) => {
        const at = getValue();
        return at ? formatDateTime(at, locale) : "—";
      },
    }),
  ]);

  return (
    <div className="mx-auto grid max-w-6xl gap-6 px-4 py-8 sm:px-6">
      <PageHeader title={t("title")} description={t("intro")} />

      <div className="grid max-w-xs gap-2">
        <Label htmlFor="application-status">{t("filterStatus")}</Label>
        <select
          id="application-status"
          className={selectClass}
          value={status}
          onChange={(e) => {
            void setStatus(e.target.value);
            void setPage(1);
          }}
        >
          <option value="all">{t("allStatuses")}</option>
          {APPLICATION_STATUSES.map((value) => (
            <option key={value} value={value}>
              {t(`status.${value}`)}
            </option>
          ))}
        </select>
      </div>

      {applications.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(applications.error)}
        </p>
      ) : (
        <DataTable
          columns={columns}
          data={applications.data?.data ?? []}
          getRowId={(row) => row.id}
          caption={t("title")}
          sort={toSort(sort)}
          onSortChange={(next) => {
            void setSort(next ? `${next.desc ? "-" : ""}${next.id}` : null);
            void setPage(1);
          }}
          page={page}
          pageCount={applications.data?.meta.last_page ?? 1}
          onPageChange={(next) => void setPage(next)}
          loading={applications.isFetching}
          empty={t("empty")}
          labels={{
            previous: table("previous"),
            next: table("next"),
            page: (current, total) => table("page", { page: current, total }),
            pagination: table("pagination"),
          }}
        />
      )}
    </div>
  );
}
