"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { ApiError, success } from "@yekkola/api-client";
import {
  adminCourseVersionsApprove,
  adminCourseVersionsReject,
  getAdminCourseVersionsIndexQueryKey,
  getAdminCourseVersionsShowQueryKey,
  useAdminCourseVersionsShow,
} from "@yekkola/api-client/endpoints/course-version";
import type { StudioLessonResource } from "@yekkola/api-client/model";
import { formatBytes, formatDateTime, formatDuration, formatMoney, type Locale } from "@yekkola/i18n";
import { ConfirmDialog } from "@yekkola/ui/blocks/confirm-dialog";
import { EmptyState } from "@yekkola/ui/blocks/empty-state";
import { StatusBadge } from "@yekkola/ui/blocks/status-badge";
import { Badge } from "@yekkola/ui/components/badge";
import { Button } from "@yekkola/ui/components/button";
import { Skeleton } from "@yekkola/ui/components/skeleton";
import { ArrowLeft, BadgeCheck, Check, X } from "lucide-react";
import Image from "next/image";
import { useLocale, useTranslations } from "next-intl";
import type { ReactNode } from "react";

import { useErrorText } from "@/features/common/errors";
import { VERSION_TONES, versionStateOf } from "@/features/common/status";
import { Link } from "@/i18n/navigation";

type LessonType = "video" | "audio" | "document" | "quiz";

/**
 * Review screen (PRD-09 FR-07, PRD-03 FR-11/FR-12): everything students would get — details, curriculum, quiz
 * answers — and, for an update, what changed since the live version. Approve (optional note) or reject (notes
 * required, shown to the professor). Only decisions the API allows are offered.
 */
export function ReviewDetail({ id }: { id: string }) {
  const t = useTranslations("admin.reviews");
  const common = useTranslations("common");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const queryClient = useQueryClient();
  const review = useAdminCourseVersionsShow(id, { query: { select: (r) => success(r).data } });

  const decide = useMutation({
    mutationFn: async ({ approve, note }: { approve: boolean; note: string }) =>
      approve ? adminCourseVersionsApprove(id, { note }) : adminCourseVersionsReject(id, { note }),
    onSuccess: () =>
      Promise.all([
        queryClient.invalidateQueries({ queryKey: getAdminCourseVersionsShowQueryKey(id) }),
        queryClient.invalidateQueries({ queryKey: getAdminCourseVersionsIndexQueryKey() }),
      ]),
  });

  if (review.isPending) {
    return (
      <div className="mx-auto grid max-w-5xl gap-4 px-4 py-8 sm:px-6">
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-96 w-full" />
      </div>
    );
  }

  if (review.isError) {
    const missing = review.error instanceof ApiError && review.error.status === 404;
    return (
      <div className="mx-auto max-w-xl px-4 py-16">
        <EmptyState title={missing ? t("notFound") : errorText(review.error)} />
      </div>
    );
  }

  const r = review.data;
  const v = r.version;
  const c = r.course;
  const state = versionStateOf(r.state);
  const title = v.title ?? c.title ?? t("untitled");

  return (
    <div className="mx-auto grid max-w-5xl gap-6 px-4 py-8 sm:px-6">
      <div className="grid gap-3">
        <Link
          href="/moderation/courses"
          className="inline-flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
        >
          <ArrowLeft aria-hidden className="size-4" />
          {t("title")}
        </Link>
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="text-2xl font-extrabold">{title}</h1>
          <StatusBadge status={state} tones={VERSION_TONES} label={t(`state.${state}`)} />
          <Badge variant="secondary">{r.is_update ? t("update") : t("firstVersion")}</Badge>
        </div>
        <p className="flex flex-wrap items-center gap-x-2 text-sm text-muted-foreground">
          <span>{t("versionNumber", { number: r.number })}</span>
          <span aria-hidden>·</span>
          <span className="inline-flex items-center gap-1">
            {t("byProfessor", { name: r.professor.display_name })}
            {r.professor.is_verified ? (
              <BadgeCheck aria-label={t("verified")} className="size-4 text-primary" />
            ) : null}
          </span>
          {r.submitted_at ? (
            <>
              <span aria-hidden>·</span>
              <span>{t("submittedOn", { date: formatDateTime(r.submitted_at, locale) })}</span>
            </>
          ) : null}
        </p>
      </div>

      {r.can.approve || r.can.reject ? (
        <section
          aria-labelledby="decision-title"
          className="grid gap-3 rounded-xl border bg-background p-4 sm:p-6"
        >
          <h2 id="decision-title" className="text-lg font-bold">
            {t("decisionTitle")}
          </h2>
          <div className="flex flex-wrap gap-2">
            {r.can.approve ? (
              <ConfirmDialog
                trigger={<Button>{t("approve")}</Button>}
                title={t("approveConfirm", { title })}
                description={r.is_update ? t("approveUpdateHint") : t("approveHint")}
                confirmLabel={t("approve")}
                cancelLabel={common("cancel")}
                onConfirm={async () => {
                  await decide.mutateAsync({ approve: true, note: "" });
                }}
              />
            ) : null}
            {r.can.reject ? (
              <ConfirmDialog
                trigger={<Button variant="destructive">{t("reject")}</Button>}
                title={t("rejectConfirm", { title })}
                description={t("rejectHint")}
                reason={{ label: t("notesToProfessor"), minLength: 3 }}
                confirmLabel={t("reject")}
                cancelLabel={common("cancel")}
                destructive
                onConfirm={async (note) => {
                  await decide.mutateAsync({ approve: false, note });
                }}
              />
            ) : null}
          </div>
          {r.can.reject && !r.can.approve ? (
            <p className="text-sm text-muted-foreground">{t("approvePaused")}</p>
          ) : null}
          {decide.isError ? (
            <p role="alert" className="text-sm text-destructive">
              {errorText(decide.error)}
            </p>
          ) : null}
        </section>
      ) : v.review_notes ? (
        <Section title={t("notesTitle")}>
          <p className="text-sm whitespace-pre-line">{v.review_notes}</p>
        </Section>
      ) : null}

      {r.changes ? (
        <Section title={t("changesTitle")}>
          {v.changelog ? (
            <div className="grid gap-1">
              <h3 className="text-sm font-semibold">{t("changelog")}</h3>
              <p className="text-sm whitespace-pre-line">{v.changelog}</p>
            </div>
          ) : null}
          <div className="grid gap-4 sm:grid-cols-3">
            {(["added", "changed", "removed"] as const).map((kind) => (
              <div key={kind} className="grid content-start gap-1">
                <h3 className="text-sm font-semibold">
                  {t(`changes.${kind}`, { count: r.changes?.[kind].length ?? 0 })}
                </h3>
                <ul className="list-disc ps-5 text-sm">
                  {(r.changes?.[kind] ?? []).map((lesson) => (
                    <li key={lesson}>{lesson}</li>
                  ))}
                </ul>
              </div>
            ))}
          </div>
        </Section>
      ) : null}

      <Section title={t("detailsTitle")}>
        <div className="grid gap-4 sm:grid-cols-[minmax(0,18rem)_1fr]">
          <div className="relative aspect-video overflow-hidden rounded-lg border bg-muted">
            {v.cover_url ? (
              <Image src={v.cover_url} alt={t("coverAlt")} fill sizes="18rem" className="object-cover" />
            ) : null}
          </div>
          <dl className="grid content-start gap-x-6 gap-y-2 sm:grid-cols-[10rem_1fr]">
            <Field label={t("fields.subtitle")}>{v.subtitle ?? "—"}</Field>
            <Field label={t("fields.category")}>{c.category?.name ?? "—"}</Field>
            <Field label={t("fields.language")}>{common(`languages.${c.language as "fr" | "en"}`)}</Field>
            <Field label={t("fields.level")}>
              {t(`levels.${c.level as "beginner" | "intermediate" | "advanced" | "all"}`)}
            </Field>
            <Field label={t("fields.price")}>
              {c.is_free ? t("free") : c.price ? formatMoney(c.price, locale) : "—"}
            </Field>
          </dl>
        </div>
        <div className="grid gap-1">
          <h3 className="text-sm font-semibold">{t("fields.description")}</h3>
          <p className="text-sm whitespace-pre-line">{v.description ?? "—"}</p>
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          <TextList title={t("fields.outcomes")} items={v.outcomes} />
          <TextList title={t("fields.requirements")} items={v.requirements} />
        </div>
      </Section>

      <Section title={t("curriculumTitle")}>
        <ol className="grid gap-4">
          {v.sections.map((section, index) => (
            <li key={section.id} className="grid gap-2">
              <h3 className="font-bold">{t("sectionNumber", { number: index + 1, title: section.title })}</h3>
              <ol className="grid gap-2">
                {section.lessons.map((lesson) => (
                  <LessonRow key={lesson.id} lesson={lesson} />
                ))}
              </ol>
            </li>
          ))}
        </ol>
      </Section>
    </div>
  );
}

function LessonRow({ lesson }: { lesson: StudioLessonResource }) {
  const t = useTranslations("admin.reviews");
  const locale = useLocale() as Locale;
  const type = lesson.type as LessonType;

  return (
    <li className="grid gap-2 rounded-lg border p-3">
      <div className="flex flex-wrap items-center gap-2">
        <span className="font-semibold">{lesson.title}</span>
        <Badge variant="secondary">{t(`lessonTypes.${type}`)}</Badge>
        {lesson.is_preview ? <Badge variant="accent">{t("preview")}</Badge> : null}
        {lesson.duration_seconds ? (
          <span className="text-xs text-muted-foreground">
            {formatDuration(lesson.duration_seconds, locale)}
          </span>
        ) : null}
        {lesson.is_content_ready ? null : <Badge variant="warning">{t("contentMissing")}</Badge>}
      </div>
      {lesson.description ? <p className="text-sm text-muted-foreground">{lesson.description}</p> : null}
      {lesson.media ? (
        <p className="text-xs text-muted-foreground">
          {t("mediaStatus", {
            status: t(
              `mediaStatuses.${lesson.media.status as "waiting_upload" | "processing" | "ready" | "errored" | "cancelled"}`,
            ),
          })}
        </p>
      ) : null}
      {lesson.document ? (
        <p className="text-xs text-muted-foreground">
          {lesson.document.name} · {formatBytes(lesson.document.size_bytes, locale)}
        </p>
      ) : null}
      {lesson.quiz ? (
        <details className="rounded-md bg-muted/40 p-2 text-sm">
          <summary className="cursor-pointer font-semibold">
            {t("quizSummary", { count: lesson.quiz.questions.length, pass: lesson.quiz.pass_mark_pct })}
          </summary>
          <ol className="mt-2 grid list-decimal gap-3 ps-5">
            {lesson.quiz.questions.map((question) => (
              <li key={question.id} className="grid gap-1">
                <p className="whitespace-pre-line">{question.prompt}</p>
                <ul className="grid gap-0.5">
                  {question.options.map((option) => (
                    <li key={option.id} className="flex items-start gap-2">
                      {option.is_correct ? (
                        <Check aria-label={t("correct")} className="mt-0.5 size-4 shrink-0 text-success" />
                      ) : (
                        <X
                          aria-label={t("incorrect")}
                          className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                        />
                      )}
                      {option.text}
                    </li>
                  ))}
                </ul>
                {question.explanation ? (
                  <p className="text-xs text-muted-foreground">
                    {t("explanation", { text: question.explanation })}
                  </p>
                ) : null}
              </li>
            ))}
          </ol>
        </details>
      ) : null}
    </li>
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

function Field({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="contents">
      <dt className="text-sm font-semibold text-muted-foreground">{label}</dt>
      <dd className="text-sm">{children}</dd>
    </div>
  );
}

function TextList({ title, items }: { title: string; items: string[] }) {
  return (
    <div className="grid content-start gap-1">
      <h3 className="text-sm font-semibold">{title}</h3>
      {items.length > 0 ? (
        <ul className="list-disc ps-5 text-sm">
          {items.map((item) => (
            <li key={item}>{item}</li>
          ))}
        </ul>
      ) : (
        <p className="text-sm text-muted-foreground">—</p>
      )}
    </div>
  );
}
