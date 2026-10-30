"use client";

import { success } from "@yekkola/api-client";
import { useAdminAuditLogIndex } from "@yekkola/api-client/endpoints/audit-log";
import type { AuditEntryResource } from "@yekkola/api-client/model";
import { formatDateTime, type Locale } from "@yekkola/i18n";
import { DataTable, dataTableColumns } from "@yekkola/ui/blocks/data-table";
import { PageHeader } from "@yekkola/ui/blocks/page-header";
import { Input } from "@yekkola/ui/components/input";
import { Label } from "@yekkola/ui/components/label";
import { useLocale, useTranslations } from "next-intl";
import { parseAsInteger, parseAsString, useQueryState } from "nuqs";
import { type FormEvent, useState } from "react";

import { useErrorText } from "@/features/common/errors";
import { useTableLabels } from "@/features/common/table";

const col = dataTableColumns<AuditEntryResource>();

/**
 * Audit log (PRD-09 FR-16): every admin action and settings change, newest first — who, what, on what, the details
 * (before/after, reason) and the IP. Read-only. Filter by event (or family, e.g. `refund`), record, or dates.
 */
export function AuditLog() {
  const t = useTranslations("admin.audit");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const labels = useTableLabels();
  const [event, setEvent] = useQueryState("event", parseAsString.withDefault(""));
  const [subject, setSubject] = useQueryState("subject", parseAsString.withDefault(""));
  const [from, setFrom] = useQueryState("from", parseAsString.withDefault(""));
  const [to, setTo] = useQueryState("to", parseAsString.withDefault(""));
  const [page, setPage] = useQueryState("page", parseAsInteger.withDefault(1));
  const [draft, setDraft] = useState({ event, subject });

  const entries = useAdminAuditLogIndex(
    {
      page,
      per_page: 25,
      ...(event ? { "filter[event]": event } : {}),
      ...(subject ? { "filter[subject_id]": subject } : {}),
      ...(from ? { "filter[from]": from } : {}),
      ...(to ? { "filter[to]": to } : {}),
    },
    { query: { select: (r) => success(r), placeholderData: (previous) => previous } },
  );

  const columns = col.columns([
    col.accessor("created_at", {
      header: t("columns.date"),
      enableSorting: false,
      cell: ({ getValue }) => <span className="whitespace-nowrap">{formatDateTime(getValue(), locale)}</span>,
    }),
    col.accessor("event", {
      header: t("columns.event"),
      enableSorting: false,
      cell: ({ getValue }) => <span className="font-mono text-xs">{getValue()}</span>,
    }),
    col.accessor((row) => row.causer?.name ?? "", {
      id: "causer",
      header: t("columns.by"),
      enableSorting: false,
      cell: ({ row }) => row.original.causer?.name ?? t("system"),
    }),
    col.accessor((row) => row.subject_id ?? "", {
      id: "subject",
      header: t("columns.on"),
      enableSorting: false,
      cell: ({ row }) =>
        row.original.subject_id ? (
          <button
            type="button"
            className="text-left font-mono text-xs text-primary hover:underline"
            onClick={() => {
              void setSubject(row.original.subject_id);
              setDraft({ ...draft, subject: row.original.subject_id ?? "" });
              void setPage(1);
            }}
          >
            {row.original.subject_type} {row.original.subject_id}
          </button>
        ) : (
          "—"
        ),
    }),
    col.accessor((row) => JSON.stringify(row.details), {
      id: "details",
      header: t("columns.details"),
      enableSorting: false,
      cell: ({ row }) => (
        <details className="max-w-md text-xs">
          <summary className="cursor-pointer text-muted-foreground">{t("show")}</summary>
          <pre className="mt-1 overflow-x-auto rounded bg-muted p-2 whitespace-pre-wrap">
            {JSON.stringify(row.original.details, null, 2)}
          </pre>
          {row.original.ip ? <p className="mt-1 text-muted-foreground">IP {row.original.ip}</p> : null}
        </details>
      ),
    }),
  ]);

  return (
    <div className="mx-auto grid max-w-6xl gap-6 px-4 py-8 sm:px-6">
      <PageHeader title={t("title")} description={t("intro")} />

      <form
        className="grid gap-4 sm:grid-cols-5 sm:items-end"
        onSubmit={(e: FormEvent) => {
          e.preventDefault();
          void setEvent(draft.event.trim() || null);
          void setSubject(draft.subject.trim() || null);
          void setPage(1);
        }}
      >
        <div className="grid gap-2">
          <Label htmlFor="audit-event">{t("columns.event")}</Label>
          <Input
            id="audit-event"
            value={draft.event}
            placeholder="refund"
            onChange={(e) => setDraft({ ...draft, event: e.target.value })}
          />
        </div>
        <div className="grid gap-2 sm:col-span-2">
          <Label htmlFor="audit-subject">{t("record")}</Label>
          <Input
            id="audit-subject"
            value={draft.subject}
            onChange={(e) => setDraft({ ...draft, subject: e.target.value })}
          />
        </div>
        <div className="grid gap-2">
          <Label htmlFor="audit-from">{t("from")}</Label>
          <Input
            id="audit-from"
            type="date"
            value={from}
            onChange={(e) => void setFrom(e.target.value || null)}
          />
        </div>
        <div className="grid gap-2">
          <Label htmlFor="audit-to">{t("to")}</Label>
          <Input id="audit-to" type="date" value={to} onChange={(e) => void setTo(e.target.value || null)} />
        </div>
        <button type="submit" className="sr-only">
          {t("filter")}
        </button>
      </form>

      {entries.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(entries.error)}
        </p>
      ) : (
        <DataTable
          columns={columns}
          data={entries.data?.data ?? []}
          getRowId={(row) => row.id}
          caption={t("title")}
          page={page}
          pageCount={entries.data?.meta.last_page ?? 1}
          onPageChange={(next) => void setPage(next)}
          loading={entries.isFetching}
          empty={t("empty")}
          labels={labels}
        />
      )}
    </div>
  );
}
