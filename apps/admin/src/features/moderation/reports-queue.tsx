"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { success } from "@yekkola/api-client";
import {
  adminReportsResolve,
  getAdminReportsIndexQueryKey,
  useAdminReportsIndex,
} from "@yekkola/api-client/endpoints/report";
import type { AdminReportResource } from "@yekkola/api-client/model";
import { formatDateTime, type Locale } from "@yekkola/i18n";
import { ConfirmDialog } from "@yekkola/ui/blocks/confirm-dialog";
import { PageHeader } from "@yekkola/ui/blocks/page-header";
import { Badge } from "@yekkola/ui/components/badge";
import { Button } from "@yekkola/ui/components/button";
import { Label } from "@yekkola/ui/components/label";
import { Star } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { parseAsInteger, parseAsString, useQueryState } from "nuqs";

import { useErrorText } from "@/features/common/errors";
import { selectClass } from "@/features/common/styles";
import { useTableLabels } from "@/features/common/table";
import { Link } from "@/i18n/navigation";

const STATUSES = ["open", "actioned", "dismissed", "all"] as const;
const TYPES = ["course", "review", "professor"] as const;
const REASONS = ["spam", "abusive", "inappropriate", "misleading", "copyright", "fraud", "other"] as const;
const ACTIONS = ["dismiss", "warn", "hide", "suspend"] as const;
type Action = (typeof ACTIONS)[number];
type Option = (typeof STATUSES)[number] | (typeof TYPES)[number] | (typeof REASONS)[number];
// A professor profile can be suspended but not hidden (the API never offers it).
type Kind = "dismiss" | "warn" | "hide_review" | "hide_course" | `suspend_${(typeof TYPES)[number]}`;
type State =
  | "visible"
  | "hidden"
  | "draft"
  | "in_review"
  | "published"
  | "unpublished"
  | "archived"
  | "taken_down"
  | "deleted"
  | "active"
  | "suspended"
  | "banned";

/**
 * Reports queue (PRD-09 FR-10): open reports, oldest first. One decision closes every open report on the same item:
 * dismiss, hide (a review) or take down (a course), warn the author, or suspend them. The API says which actions
 * this staff member may take (`can`).
 */
export function ReportsQueue() {
  const t = useTranslations("admin.reportsQueue");
  const common = useTranslations("common");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const labels = useTableLabels();
  const queryClient = useQueryClient();
  const [status, setStatus] = useQueryState("status", parseAsString.withDefault("open"));
  const [type, setType] = useQueryState("type", parseAsString.withDefault("all"));
  const [reason, setReason] = useQueryState("reason", parseAsString.withDefault("all"));
  const [page, setPage] = useQueryState("page", parseAsInteger.withDefault(1));

  const reports = useAdminReportsIndex(
    {
      page,
      per_page: 20,
      "filter[status]": status,
      ...(type === "all" ? {} : { "filter[type]": type }),
      ...(reason === "all" ? {} : { "filter[reason]": reason }),
    },
    { query: { select: (r) => success(r), placeholderData: (previous) => previous } },
  );
  const resolve = useMutation({
    mutationFn: ({ id, action, note }: { id: string; action: Action; note: string }) =>
      adminReportsResolve(id, { action, note: note || null }),
    onSettled: () => queryClient.invalidateQueries({ queryKey: getAdminReportsIndexQueryKey() }),
  });
  const lastPage = reports.data?.meta.last_page ?? 1;

  const filter = (
    id: string,
    label: string,
    value: string,
    set: (value: string) => void,
    options: readonly string[],
  ) => (
    <div className="grid gap-2">
      <Label htmlFor={id}>{label}</Label>
      <select
        id={id}
        className={selectClass}
        value={value}
        onChange={(e) => {
          set(e.target.value);
          void setPage(1);
        }}
      >
        {options.map((o) => (
          <option key={o} value={o}>
            {t(`options.${o as Option}`)}
          </option>
        ))}
      </select>
    </div>
  );

  const actionButton = (r: AdminReportResource, action: Action) => {
    // Hiding and suspending mean different things for a review, a course and a professor.
    const kind = (action === "hide" || action === "suspend" ? `${action}_${r.target.type}` : action) as Kind;

    return (
      <ConfirmDialog
        key={action}
        trigger={
          <Button
            size="sm"
            variant={action === "dismiss" ? "outline" : action === "warn" ? "secondary" : "destructive"}
          >
            {t(`actions.${kind}.label`)}
          </Button>
        }
        title={t(`actions.${kind}.confirm`)}
        description={t(`actions.${kind}.hint`, { count: r.open_reports_on_target })}
        reason={action === "dismiss" ? undefined : { label: t("note"), minLength: 3 }}
        confirmLabel={t(`actions.${kind}.label`)}
        cancelLabel={common("cancel")}
        destructive={action === "hide" || action === "suspend"}
        onConfirm={async (note) => {
          await resolve.mutateAsync({ id: r.id, action, note });
        }}
        error={resolve.isError && resolve.variables?.id === r.id ? errorText(resolve.error) : undefined}
        onOpenChange={(open) => {
          if (open) resolve.reset();
        }}
      />
    );
  };

  return (
    <div className="mx-auto grid max-w-5xl gap-6 px-4 py-8 sm:px-6">
      <PageHeader title={t("title")} description={t("intro")} />

      <div className="grid gap-4 sm:grid-cols-3">
        {filter("report-status", t("status"), status, (v) => void setStatus(v), STATUSES)}
        {filter("report-type", t("type"), type, (v) => void setType(v), ["all", ...TYPES])}
        {filter("report-reason", t("reason"), reason, (v) => void setReason(v), ["all", ...REASONS])}
      </div>

      {reports.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(reports.error)}
        </p>
      ) : null}
      {reports.data?.data.length === 0 ? <p className="text-sm text-muted-foreground">{t("empty")}</p> : null}

      <ul className="grid gap-3" aria-busy={reports.isFetching}>
        {(reports.data?.data ?? []).map((r) => {
          const rating = Number(r.target.rating ?? 0);

          return (
            <li key={r.id} className="grid gap-3 rounded-xl border bg-background p-4">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="flex flex-wrap items-center gap-2">
                  <Badge variant="outline">{t(`options.${r.target.type as Option}`)}</Badge>
                  <span className="font-semibold">{t(`options.${r.reason as Option}`)}</span>
                  {r.open_reports_on_target > 1 ? (
                    <Badge variant="secondary">{t("openOnItem", { count: r.open_reports_on_target })}</Badge>
                  ) : null}
                </span>
                <span className="text-xs text-muted-foreground">
                  {t("reportedBy", { name: r.reporter?.name ?? "—" })} ·{" "}
                  {formatDateTime(r.created_at, locale)}
                </span>
              </div>
              {r.details ? (
                <p className="text-sm whitespace-pre-line">{t("details", { text: r.details })}</p>
              ) : null}

              <div className="grid gap-1 rounded-lg bg-muted/50 p-3 text-sm">
                {r.target.exists ? (
                  <>
                    <span className="flex flex-wrap items-center gap-2 font-medium">
                      {r.target.title ?? r.target.course_slug ?? r.target.professor_slug}
                      {r.target.state ? (
                        <Badge variant="outline">{t(`states.${r.target.state as State}`)}</Badge>
                      ) : null}
                    </span>
                    {r.target.type === "review" ? (
                      <span
                        className="flex items-center gap-0.5 text-amber-700"
                        aria-label={t("stars", { count: rating })}
                      >
                        {Array.from({ length: rating }, (_, i) => (
                          <Star key={i} aria-hidden className="size-4 fill-current" />
                        ))}
                      </span>
                    ) : null}
                    {r.target.excerpt ? <p className="whitespace-pre-line">{r.target.excerpt}</p> : null}
                    {r.target.author ? (
                      <span className="text-xs text-muted-foreground">
                        {t("author")}{" "}
                        <Link href={`/users/${r.target.author.id}`} className="underline underline-offset-2">
                          {r.target.author.name ?? "—"}
                        </Link>
                        {r.target.author.status !== "active"
                          ? ` · ${t(`states.${r.target.author.status as State}`)}`
                          : null}
                      </span>
                    ) : null}
                  </>
                ) : (
                  <span className="text-muted-foreground">{t("gone")}</span>
                )}
              </div>

              {r.status !== "open" ? (
                <p className="text-xs text-muted-foreground">
                  {t(`resolved.${(r.action ?? "dismiss") as Action}`, {
                    name: r.handled_by?.name ?? "—",
                    date: r.handled_at ? formatDateTime(r.handled_at, locale) : "—",
                  })}
                  {r.resolution_note ? ` — ${r.resolution_note}` : null}
                </p>
              ) : null}
              {ACTIONS.some((a) => r.can[a]) ? (
                <div className="flex flex-wrap justify-end gap-2">
                  {ACTIONS.filter((a) => r.can[a]).map((a) => actionButton(r, a))}
                </div>
              ) : null}
            </li>
          );
        })}
      </ul>

      {lastPage > 1 ? (
        <nav aria-label={labels.pagination} className="flex items-center justify-between gap-4">
          <Button variant="outline" disabled={page <= 1} onClick={() => void setPage(page - 1)}>
            {labels.previous}
          </Button>
          <span className="text-sm text-muted-foreground">{labels.page(page, lastPage)}</span>
          <Button variant="outline" disabled={page >= lastPage} onClick={() => void setPage(page + 1)}>
            {labels.next}
          </Button>
        </nav>
      ) : null}
    </div>
  );
}
