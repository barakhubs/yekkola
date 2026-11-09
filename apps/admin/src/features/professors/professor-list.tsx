"use client";

import { success } from "@yekkola/api-client";
import { useAdminProfessorsIndex } from "@yekkola/api-client/endpoints/professor";
import type { AdminProfessorResource } from "@yekkola/api-client/model";
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

import {
  KYC_STATES,
  KYC_TONES,
  PROFESSOR_STATES,
  PROFESSOR_TONES,
  kycStateOf,
  professorStateOf,
} from "./status";

const col = dataTableColumns<AdminProfessorResource>();

/** Professors (PRD-09 FR-06): find by name, filter by standing or identity check. Newest first. */
export function ProfessorList() {
  const t = useTranslations("admin.professors");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const labels = useTableLabels();
  const [search, setSearch] = useQueryState("q", parseAsString.withDefault(""));
  const [status, setStatus] = useQueryState("status", parseAsString.withDefault("all"));
  const [kyc, setKyc] = useQueryState("kyc", parseAsString.withDefault("all"));
  const [sort, setSort] = useQueryState("sort", parseAsString);
  const [page, setPage] = useQueryState("page", parseAsInteger.withDefault(1));
  const [draft, setDraft] = useState(search);

  const professors = useAdminProfessorsIndex(
    {
      page,
      per_page: 25,
      ...(search ? { "filter[search]": search } : {}),
      ...(status === "all" ? {} : { "filter[status]": status }),
      ...(kyc === "all" ? {} : { "filter[kyc_status]": kyc }),
      ...(sort ? { sort } : {}),
    },
    { query: { select: (r) => success(r), placeholderData: (previous) => previous } },
  );

  const columns = col.columns([
    col.accessor("display_name", {
      header: t("columns.name"),
      cell: ({ row }) => (
        <Link href={`/professors/${row.original.id}`} className="font-semibold text-primary hover:underline">
          {row.original.display_name}
        </Link>
      ),
    }),
    col.accessor((row) => row.phone ?? "", { id: "phone", header: t("columns.phone"), enableSorting: false }),
    col.accessor("status", {
      header: t("columns.status"),
      enableSorting: false,
      cell: ({ getValue }) => {
        const value = professorStateOf(getValue());
        return <StatusBadge status={value} tones={PROFESSOR_TONES} label={t(`status.${value}`)} />;
      },
    }),
    col.accessor("kyc_status", {
      header: t("columns.kyc"),
      enableSorting: false,
      cell: ({ getValue }) => {
        const value = kycStateOf(getValue());
        return <StatusBadge status={value} tones={KYC_TONES} label={t(`kyc.${value}`)} />;
      },
    }),
    col.accessor("student_count", { header: t("columns.students") }),
    col.accessor("member_since", {
      id: "created_at",
      header: t("columns.created"),
      cell: ({ getValue }) => formatDateTime(getValue(), locale),
    }),
  ]);

  const filter = (
    id: string,
    value: string,
    set: (v: string) => void,
    options: readonly string[],
    prefix: string,
    all: string,
  ) => (
    <div className="grid gap-2">
      <Label htmlFor={id}>{t(`columns.${prefix === "status" ? "status" : "kyc"}`)}</Label>
      <select
        id={id}
        className={selectClass}
        value={value}
        onChange={(e) => {
          set(e.target.value);
          void setPage(1);
        }}
      >
        <option value="all">{all}</option>
        {options.map((option) => (
          <option key={option} value={option}>
            {t(`${prefix}.${option}` as never)}
          </option>
        ))}
      </select>
    </div>
  );

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
          <Label htmlFor="professor-search">{t("search")}</Label>
          <Input
            id="professor-search"
            type="search"
            value={draft}
            onChange={(e) => setDraft(e.target.value)}
            placeholder={t("searchHint")}
          />
        </form>
        {filter(
          "professor-status",
          status,
          (v) => void setStatus(v),
          PROFESSOR_STATES,
          "status",
          t("allStatuses"),
        )}
        {filter("professor-kyc", kyc, (v) => void setKyc(v), KYC_STATES, "kyc", t("allKyc"))}
      </div>

      {professors.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(professors.error)}
        </p>
      ) : (
        <DataTable
          columns={columns}
          data={professors.data?.data ?? []}
          getRowId={(row) => row.id}
          caption={t("title")}
          sort={toSort(sort)}
          onSortChange={(next) => {
            void setSort(fromSort(next));
            void setPage(1);
          }}
          page={page}
          pageCount={professors.data?.meta.last_page ?? 1}
          onPageChange={(next) => void setPage(next)}
          loading={professors.isFetching}
          empty={t("empty")}
          labels={labels}
        />
      )}
    </div>
  );
}
