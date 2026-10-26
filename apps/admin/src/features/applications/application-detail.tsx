"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { ApiError, apiUrl, success } from "@yekkola/api-client";
import {
  adminProfessorApplicationsApprove,
  adminProfessorApplicationsReject,
  adminProfessorApplicationsRequestInfo,
  adminProfessorApplicationsStartReview,
  getAdminProfessorApplicationsIndexQueryKey,
  getAdminProfessorApplicationsShowQueryKey,
  useAdminProfessorApplicationsShow,
} from "@yekkola/api-client/endpoints/professor-application";
import { formatBytes, formatDateTime, type Locale } from "@yekkola/i18n";
import { ConfirmDialog } from "@yekkola/ui/blocks/confirm-dialog";
import { EmptyState } from "@yekkola/ui/blocks/empty-state";
import { StatusBadge } from "@yekkola/ui/blocks/status-badge";
import { Button } from "@yekkola/ui/components/button";
import { Skeleton } from "@yekkola/ui/components/skeleton";
import { ArrowLeft, FileText } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import type { ReactNode } from "react";

import { useErrorText } from "@/features/common/errors";
import { APPLICATION_TONES, applicationStatusOf } from "@/features/common/status";
import { Link } from "@/i18n/navigation";

type Decision = "approve" | "reject" | "request_info";

/**
 * One application (PRD-02 FR-05–FR-08, PRD-09 FR-06): what the applicant sent, their credential files, and the
 * decision. Rejecting or asking for more information needs a note, sent to the applicant; every decision is
 * audited by the API. Only decisions the API allows are offered.
 */
export function ApplicationDetail({ id }: { id: string }) {
  const t = useTranslations("admin.applications");
  const common = useTranslations("common");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const queryClient = useQueryClient();
  const application = useAdminProfessorApplicationsShow(id, { query: { select: (r) => success(r).data } });

  const refresh = () =>
    Promise.all([
      queryClient.invalidateQueries({ queryKey: getAdminProfessorApplicationsShowQueryKey(id) }),
      queryClient.invalidateQueries({ queryKey: getAdminProfessorApplicationsIndexQueryKey() }),
    ]);

  const startReview = useMutation({
    mutationFn: () => adminProfessorApplicationsStartReview(id),
    onSettled: refresh,
  });
  const decide = useMutation({
    mutationFn: async ({ decision, note }: { decision: Decision; note: string }) => {
      // An empty note is fine for approving (the API reads it as none); rejecting needs one.
      const body = { note };
      if (decision === "approve") return adminProfessorApplicationsApprove(id, body);
      if (decision === "reject") return adminProfessorApplicationsReject(id, body);
      return adminProfessorApplicationsRequestInfo(id, body);
    },
    // Refused too (e.g. another reviewer decided first): read it again so status and actions are current.
    onSettled: refresh,
  });

  if (application.isPending) {
    return (
      <div className="mx-auto grid max-w-5xl gap-4 px-4 py-8 sm:px-6">
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }

  if (application.isError) {
    const missing = application.error instanceof ApiError && application.error.status === 404;
    return (
      <div className="mx-auto max-w-xl px-4 py-16">
        <EmptyState title={missing ? t("notFound") : errorText(application.error)} />
      </div>
    );
  }

  const a = application.data;
  const status = applicationStatusOf(a.status);
  const name = a.full_name ?? a.applicant.name ?? t("unnamed");
  // ConfirmDialog stays open when this throws; the message shows under the actions.
  const confirm = (decision: Decision) => async (note: string) => {
    await decide.mutateAsync({ decision, note });
  };
  // Each dialog shows its own failure, inside the dialog (the page behind it is hidden from view and from
  // screen readers while it is open); reopening starts clean.
  const dialogProps = (decision: Decision) => ({
    onConfirm: confirm(decision),
    error: decide.isError && decide.variables?.decision === decision ? errorText(decide.error) : undefined,
    onOpenChange: (open: boolean) => {
      if (open) decide.reset();
    },
  });

  return (
    <div className="mx-auto grid max-w-5xl gap-6 px-4 py-8 sm:px-6">
      <div className="grid gap-3">
        <Link
          href="/applications"
          className="inline-flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
        >
          <ArrowLeft aria-hidden className="size-4" />
          {t("title")}
        </Link>
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="text-2xl font-extrabold">{name}</h1>
          <StatusBadge status={status} tones={APPLICATION_TONES} label={t(`status.${status}`)} />
        </div>
        {a.submitted_at ? (
          <p className="text-sm text-muted-foreground">
            {t("submittedOn", { date: formatDateTime(a.submitted_at, locale) })}
          </p>
        ) : null}
      </div>

      {a.can.start_review || a.can.decide ? (
        <section
          aria-labelledby="decision-title"
          className="grid gap-3 rounded-xl border bg-background p-4 sm:p-6"
        >
          <h2 id="decision-title" className="text-lg font-bold">
            {t("decisionTitle")}
          </h2>
          <div className="flex flex-wrap gap-2">
            {a.can.start_review ? (
              <Button
                variant="outline"
                onClick={() => startReview.mutate()}
                disabled={startReview.isPending}
                aria-busy={startReview.isPending}
              >
                {t("startReview")}
              </Button>
            ) : null}
            {a.can.decide ? (
              <>
                <ConfirmDialog
                  trigger={<Button>{t("approve")}</Button>}
                  title={t("approveConfirm", { name })}
                  description={t("approveHint")}
                  confirmLabel={t("approve")}
                  cancelLabel={common("cancel")}
                  {...dialogProps("approve")}
                />
                <ConfirmDialog
                  trigger={<Button variant="outline">{t("requestInfo")}</Button>}
                  title={t("requestInfoConfirm")}
                  description={t("requestInfoHint")}
                  reason={{ label: t("noteToApplicant"), minLength: 3 }}
                  confirmLabel={t("requestInfo")}
                  cancelLabel={common("cancel")}
                  {...dialogProps("request_info")}
                />
                <ConfirmDialog
                  trigger={<Button variant="destructive">{t("reject")}</Button>}
                  title={t("rejectConfirm", { name })}
                  description={t("rejectHint")}
                  reason={{ label: t("noteToApplicant"), minLength: 3 }}
                  confirmLabel={t("reject")}
                  cancelLabel={common("cancel")}
                  destructive
                  {...dialogProps("reject")}
                />
              </>
            ) : null}
          </div>
          {startReview.isError ? (
            <p role="alert" className="text-sm text-destructive">
              {errorText(startReview.error)}
            </p>
          ) : null}
        </section>
      ) : null}

      <Section title={t("applicantTitle")}>
        <Fields
          rows={[
            [t("fields.name"), name],
            [t("fields.phone"), a.applicant.phone ?? "—"],
            [t("fields.province"), a.province?.name ?? "—"],
          ]}
        />
      </Section>

      <Section title={t("teachingTitle")}>
        <Fields
          rows={[
            [t("fields.subjects"), a.subjects.join(", ") || "—"],
            [
              t("fields.levels"),
              a.levels
                .map((level) =>
                  t(`levels.${level as "secondary" | "exetat" | "university" | "professional"}`),
                )
                .join(", ") || "—",
            ],
            [
              t("fields.experience"),
              a.years_experience === null ? "—" : t("years", { count: a.years_experience }),
            ],
            [t("fields.institutions"), a.institutions ?? "—"],
            [t("fields.credentials"), a.credentials ?? "—"],
            [
              t("fields.sample"),
              a.sample_url ? (
                <a
                  href={a.sample_url}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="text-primary underline"
                >
                  {a.sample_url}
                </a>
              ) : (
                "—"
              ),
            ],
          ]}
        />
        <div className="grid gap-1">
          <h3 className="text-sm font-semibold">{t("fields.motivation")}</h3>
          <p className="text-sm whitespace-pre-line">{a.motivation ?? "—"}</p>
        </div>
      </Section>

      <Section title={t("filesTitle")}>
        {a.credential_files.length === 0 ? (
          <p className="text-sm text-muted-foreground">{t("noFiles")}</p>
        ) : (
          <ul className="grid gap-2">
            {a.credential_files.map((file) =>
              file.id ? (
                <li key={file.id}>
                  {/* Streamed by the API to staff only, with the session cookie; each view is audited. */}
                  <a
                    href={apiUrl(`/api/v1/admin/professor-applications/${a.id}/credentials/${file.id}`)}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex items-center gap-2 text-sm text-primary underline"
                  >
                    <FileText aria-hidden className="size-4" />
                    {file.name} · {formatBytes(file.size, locale)}
                  </a>
                </li>
              ) : null,
            )}
          </ul>
        )}
      </Section>

      <Section title={t("historyTitle")}>
        <Fields
          rows={[
            [
              t("fields.reviewStarted"),
              a.review_started_at ? formatDateTime(a.review_started_at, locale) : "—",
            ],
            [t("fields.infoRequest"), a.info_request_note ?? "—"],
            [t("fields.decisionNote"), a.decision_note ?? "—"],
            [t("fields.decided"), a.decided_at ? formatDateTime(a.decided_at, locale) : "—"],
          ]}
        />
      </Section>
    </div>
  );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="grid gap-4 rounded-xl border bg-background p-4 sm:p-6">
      <h2 className="text-lg font-bold">{title}</h2>
      {children}
    </section>
  );
}

function Fields({ rows }: { rows: Array<[string, ReactNode]> }) {
  return (
    <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-[12rem_1fr]">
      {rows.map(([label, value]) => (
        <div key={label} className="contents">
          <dt className="text-sm font-semibold text-muted-foreground">{label}</dt>
          <dd className="text-sm whitespace-pre-line">{value}</dd>
        </div>
      ))}
    </dl>
  );
}
