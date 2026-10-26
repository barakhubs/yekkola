"use client";

import { success } from "@yekkola/api-client";
import { useAdminCourseVersionsIndex } from "@yekkola/api-client/endpoints/course-version";
import type { AdminCourseVersionResource } from "@yekkola/api-client/model";
import { formatDateTime, type Locale } from "@yekkola/i18n";
import { DataTable, dataTableColumns, type DataTableSort } from "@yekkola/ui/blocks/data-table";
import { PageHeader } from "@yekkola/ui/blocks/page-header";
import { StatusBadge } from "@yekkola/ui/blocks/status-badge";
import { Badge } from "@yekkola/ui/components/badge";
import { Label } from "@yekkola/ui/components/label";
import { BadgeCheck } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { parseAsInteger, parseAsString, useQueryState } from "nuqs";

import { useErrorText } from "@/features/common/errors";
import { selectClass } from "@/features/common/styles";
import { VERSION_STATES, VERSION_TONES, versionStateOf } from "@/features/common/status";
import { Link } from "@/i18n/navigation";

const col = dataTableColumns<AdminCourseVersionResource>();

function toSort(param: string | null): DataTableSort | null {
  return param ? { id: param.replace(/^-/, ""), desc: param.startsWith("-") } : null;
}

/**
 * Course review queue (PRD-09 FR-07, PRD-03 FR-11): versions waiting for review, oldest first, marked as a first
 * version or an update of a live course.
 */
export function ReviewQueue() {
  const t = useTranslations("admin.reviews");
  const table = useTranslations("admin.table");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const [state, setState] = useQueryState("state", parseAsString.withDefault("in_review"));
  const [sort, setSort] = useQueryState("sort", parseAsString);
  const [page, setPage] = useQueryState("page", parseAsInteger.withDefault(1));

  const versions = useAdminCourseVersionsIndex(
    {
      page,
      per_page: 25,
      ...(state === "all" ? {} : { "filter[state]": state }),
      ...(sort ? { sort } : {}),
    },
    { query: { select: (r) => success(r), placeholderData: (previous) => previous } },
  );

  const columns = col.columns([
    col.accessor((row) => row.title ?? row.course.title ?? "", {
      id: "title",
      header: t("columns.course"),
      enableSorting: false,
      cell: ({ row }) => (
        <span className="grid gap-0.5">
          <Link
            href={`/moderation/courses/${row.original.id}`}
            className="font-semibold text-primary hover:underline"
          >
            {row.original.title ?? row.original.course.title ?? t("untitled")}
          </Link>
          <span className="text-xs text-muted-foreground">
            {t("versionNumber", { number: row.original.number })}
          </span>
        </span>
      ),
    }),
    col.accessor((row) => row.professor.display_name, {
      id: "professor",
      header: t("columns.professor"),
      enableSorting: false,
      cell: ({ row }) => (
        <span className="inline-flex items-center gap-1">
          {row.original.professor.display_name}
          {row.original.professor.is_verified ? (
            <BadgeCheck aria-label={t("verified")} className="size-4 text-primary" />
          ) : null}
        </span>
      ),
    }),
    col.accessor("is_update", {
      header: t("columns.kind"),
      enableSorting: false,
      cell: ({ getValue }) => (
        <Badge variant="secondary">{getValue() ? t("update") : t("firstVersion")}</Badge>
      ),
    }),
    col.accessor("state", {
      header: t("columns.state"),
      enableSorting: false,
      cell: ({ getValue }) => {
        const value = versionStateOf(getValue());
        return <StatusBadge status={value} tones={VERSION_TONES} label={t(`state.${value}`)} />;
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
        <Label htmlFor="version-state">{t("filterState")}</Label>
        <select
          id="version-state"
          className={selectClass}
          value={state}
          onChange={(e) => {
            void setState(e.target.value);
            void setPage(1);
          }}
        >
          <option value="all">{t("allStates")}</option>
          {VERSION_STATES.map((value) => (
            <option key={value} value={value}>
              {t(`state.${value}`)}
            </option>
          ))}
        </select>
      </div>

      {versions.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(versions.error)}
        </p>
      ) : (
        <DataTable
          columns={columns}
          data={versions.data?.data ?? []}
          getRowId={(row) => row.id}
          caption={t("title")}
          sort={toSort(sort)}
          onSortChange={(next) => {
            void setSort(next ? `${next.desc ? "-" : ""}${next.id}` : null);
            void setPage(1);
          }}
          page={page}
          pageCount={versions.data?.meta.last_page ?? 1}
          onPageChange={(next) => void setPage(next)}
          loading={versions.isFetching}
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
