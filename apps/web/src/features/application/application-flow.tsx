"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ApiError, success } from "@yekkola/api-client";
import {
  getProfessorApplicationShowQueryKey,
  professorApplicationShow,
} from "@yekkola/api-client/endpoints/application";
import { getMeShowQueryKey } from "@yekkola/api-client/endpoints/profile";
import type { ProfessorApplicationResource } from "@yekkola/api-client/model";
import { formatDate, formatDateTime, type Locale } from "@yekkola/i18n";
import { EmptyState } from "@yekkola/ui/blocks/empty-state";
import { Button } from "@yekkola/ui/components/button";
import { Skeleton } from "@yekkola/ui/components/skeleton";
import { CheckCircle2, Clock, XCircle } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { type ReactNode, useEffect, useRef, useState } from "react";

import { useSession } from "@/features/auth/hooks/use-session";
import { Link } from "@/i18n/navigation";

import { ApplicationForm } from "./application-form";

/** The applicant's latest application, or null when they never applied (`application.not_found`). */
export function useApplication() {
  return useQuery({
    queryKey: getProfessorApplicationShowQueryKey(),
    queryFn: async (): Promise<ProfessorApplicationResource | null> => {
      try {
        return success(await professorApplicationShow()).data;
      } catch (error) {
        if (error instanceof ApiError && error.code === "application.not_found") return null;
        throw error;
      }
    },
    retry: (failures, error) => !(error instanceof ApiError && error.status < 500) && failures < 2,
  });
}

/**
 * Becoming a professor (PRD-02 FR-01–FR-06): the form while it's a draft (or more information was asked for),
 * then where it stands — submitted, under review, approved (the studio opens), rejected (with the reason and
 * when a new application is possible) or expired.
 */
export function ApplicationFlow() {
  const t = useTranslations("web.apply");
  const errors = useTranslations("errors");
  const session = useSession();
  const queryClient = useQueryClient();
  const application = useApplication();
  const [startOver, setStartOver] = useState(false);
  const isProfessor = session.user?.roles.includes("professor") ?? false;
  const approved = application.data?.status === "approved";

  // Approved since the session was read: refresh it so the studio opens.
  useEffect(() => {
    if (approved && !isProfessor) void queryClient.invalidateQueries({ queryKey: getMeShowQueryKey() });
  }, [approved, isProfessor, queryClient]);

  if (application.isPending) {
    return (
      <Frame>
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-64 w-full" />
      </Frame>
    );
  }

  if (application.isError) {
    return (
      <Frame>
        <EmptyState
          title={
            application.error instanceof ApiError
              ? (application.error.title ?? errors("generic"))
              : errors("network")
          }
          action={
            <Button variant="outline" onClick={() => application.refetch()}>
              {t("retry")}
            </Button>
          }
        />
      </Frame>
    );
  }

  const a = application.data;

  if (isProfessor || a?.status === "approved") {
    return (
      <Frame>
        <Outcome
          icon={<CheckCircle2 aria-hidden className="size-10 text-success" />}
          title={t("approvedTitle")}
        >
          <p>{t("approvedBody")}</p>
          <Button asChild>
            <Link href="/studio/courses">{t("openStudio")}</Link>
          </Button>
        </Outcome>
      </Frame>
    );
  }

  if (a && (a.status === "submitted" || a.status === "under_review")) {
    return (
      <Frame>
        <StatusPanel application={a} />
      </Frame>
    );
  }

  if (a?.status === "rejected" && !startOver) {
    return (
      <Frame>
        <Rejected application={a} onStartOver={() => setStartOver(true)} />
      </Frame>
    );
  }

  // No application yet, a draft, more information requested, expired, or starting again after a rejection.
  const editable = a && a.is_editable ? a : null;

  return (
    <Frame>
      <header className="grid gap-1">
        <h1 className="text-2xl font-extrabold sm:text-3xl">{t("title")}</h1>
        <p className="text-muted-foreground">{t("intro")}</p>
      </header>
      {a?.status === "expired" ? (
        <p className="rounded-lg border bg-background p-3 text-sm">{t("expiredNotice")}</p>
      ) : null}
      {/* No key: the first autosave creates the draft, and the form must stay where the applicant is. */}
      <ApplicationForm application={editable} />
    </Frame>
  );
}

function Frame({ children }: { children: ReactNode }) {
  return <div className="mx-auto grid max-w-3xl gap-6 px-4 py-8 sm:py-12">{children}</div>;
}

function Outcome({ icon, title, children }: { icon: ReactNode; title: string; children: ReactNode }) {
  const heading = useRef<HTMLHeadingElement>(null);
  // A new screen (e.g. right after submitting): move focus to its title so it is read out.
  useEffect(() => heading.current?.focus(), [title]);

  return (
    <section className="grid justify-items-center gap-4 rounded-xl border bg-background p-6 text-center sm:p-10">
      {icon}
      <h1 ref={heading} tabIndex={-1} className="text-2xl font-extrabold focus:outline-none">
        {title}
      </h1>
      {children}
    </section>
  );
}

function StatusPanel({ application }: { application: ProfessorApplicationResource }) {
  const t = useTranslations("web.apply");
  const locale = useLocale() as Locale;
  const steps = ["submitted", "under_review", "decision"] as const;
  const current = application.status === "under_review" ? 1 : 0;

  return (
    <Outcome
      icon={<Clock aria-hidden className="size-10 text-primary" />}
      title={t(`status.${application.status as "submitted" | "under_review"}`)}
    >
      <p>{t("pendingBody")}</p>
      {application.submitted_at ? (
        <p className="text-sm text-muted-foreground">
          {t("submittedOn", { date: formatDateTime(application.submitted_at, locale) })}
        </p>
      ) : null}
      <ol className="grid w-full max-w-md gap-2 text-start" aria-label={t("progressLabel")}>
        {steps.map((step, index) => (
          <li
            key={step}
            aria-current={index === current ? "step" : undefined}
            className="flex items-center gap-3 rounded-md border p-3 text-sm aria-[current=step]:border-primary aria-[current=step]:font-semibold"
          >
            <span
              aria-hidden
              className={
                index <= current
                  ? "grid size-6 place-items-center rounded-full bg-primary text-xs text-primary-foreground"
                  : "grid size-6 place-items-center rounded-full border text-xs"
              }
            >
              {index + 1}
            </span>
            {t(`timeline.${step}`)}
          </li>
        ))}
      </ol>
      <Button asChild variant="outline">
        <Link href="/my-courses">{t("backToLearning")}</Link>
      </Button>
    </Outcome>
  );
}

function Rejected({
  application,
  onStartOver,
}: {
  application: ProfessorApplicationResource;
  onStartOver: () => void;
}) {
  const t = useTranslations("web.apply");
  const locale = useLocale() as Locale;
  const [now] = useState(() => Date.now());
  const reapplyAt = application.reapply_at;
  // Without a date the API decides (it answers with the cooldown if there is one).
  const canReapply = reapplyAt === null || new Date(reapplyAt).getTime() <= now;

  return (
    <Outcome icon={<XCircle aria-hidden className="size-10 text-destructive" />} title={t("rejectedTitle")}>
      {application.decision_note ? (
        <div className="grid w-full gap-1 rounded-lg border bg-muted/40 p-4 text-start">
          <p className="text-sm font-semibold">{t("reviewerNote")}</p>
          <p className="text-sm whitespace-pre-line">{application.decision_note}</p>
        </div>
      ) : null}
      {canReapply ? (
        <Button onClick={onStartOver}>{t("applyAgain")}</Button>
      ) : reapplyAt ? (
        <p className="text-sm text-muted-foreground">
          {t("reapplyOn", { date: formatDate(reapplyAt, locale) })}
        </p>
      ) : null}
      <Button asChild variant="outline">
        <Link href="/my-courses">{t("backToLearning")}</Link>
      </Button>
    </Outcome>
  );
}
