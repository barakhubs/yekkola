"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import {
  getProfessorCoursesIndexQueryKey,
  getProfessorCoursesShowQueryKey,
  professorCoursesArchive,
  professorCoursesDestroy,
  professorCoursesDraftStart,
  professorCoursesRepublish,
  professorCoursesSubmit,
  professorCoursesUnpublish,
  professorCoursesWithdraw,
} from "@yekkola/api-client/endpoints/course";
import { getProfessorDraftShowQueryKey } from "@yekkola/api-client/endpoints/draft";
import type { StudioCourseResourceCan } from "@yekkola/api-client/model";
import { formatDateTime, type Locale } from "@yekkola/i18n";
import { ConfirmDialog } from "@yekkola/ui/blocks/confirm-dialog";
import { Button } from "@yekkola/ui/components/button";
import { Skeleton } from "@yekkola/ui/components/skeleton";
import { CheckCircle2, Circle, MessageSquareWarning } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import type { ReactNode } from "react";

import { Link, useRouter } from "@/i18n/navigation";

import { useErrorText, useRefreshCourse, useStudioCourse, useStudioDraft } from "../hooks";

/** Each checklist item and the tab where it is fixed (PRD-03 FR-10, keys from the API). */
const CHECKLIST = [
  ["title", "details"],
  ["description", "details"],
  ["outcomes", "details"],
  ["cover", "details"],
  ["category", "details"],
  ["pricing", "pricing"],
  ["sections", "curriculum"],
  ["lessons", "curriculum"],
  ["lesson_content", "curriculum"],
] as const;

/**
 * Review tab: what still blocks submission, submit / withdraw (FR-10, FR-11), the reviewer's notes after a
 * rejection (FR-12), and publishing actions (FR-13, FR-16, FR-17). The API decides every transition.
 */
export function ReviewTab({ courseId }: { courseId: string }) {
  const t = useTranslations("web.studio");
  const locale = useLocale() as Locale;
  const course = useStudioCourse(courseId);
  const hasDraft = Boolean(course.data?.draft_version);
  const draft = useStudioDraft(courseId, hasDraft);

  if (!course.data || (hasDraft && draft.isPending)) {
    return <Skeleton className="h-64 w-full" />;
  }

  const c = course.data;
  const d = draft.data;
  const missing = new Set(d?.checklist?.missing ?? []);

  return (
    <div className="grid gap-6">
      {d?.state === "rejected" && d.review_notes ? (
        <Panel title={t("reviewNotesTitle")}>
          <div className="flex items-start gap-3 rounded-lg border border-destructive/40 bg-destructive/5 p-3">
            <MessageSquareWarning aria-hidden className="mt-0.5 size-5 shrink-0 text-destructive" />
            <div className="grid gap-1">
              <p className="whitespace-pre-line">{d.review_notes}</p>
              {d.reviewed_at ? (
                <p className="text-xs text-muted-foreground">{formatDateTime(d.reviewed_at, locale)}</p>
              ) : null}
            </div>
          </div>
          <p className="text-sm text-muted-foreground">{t("reviewNotesHint")}</p>
        </Panel>
      ) : null}

      {d ? (
        <Panel title={t("submitTitle")} description={t("submitIntro")}>
          {d.state === "in_review" ? (
            <InReview courseId={courseId} submittedAt={d.submitted_at} />
          ) : (
            <>
              <ul className="grid gap-2">
                {CHECKLIST.map(([item, tab]) => {
                  const done = !missing.has(item);
                  return (
                    <li key={item} className="flex items-start gap-2 text-sm">
                      {done ? (
                        <CheckCircle2 aria-hidden className="mt-0.5 size-4 shrink-0 text-success" />
                      ) : (
                        <Circle aria-hidden className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                      )}
                      <span>
                        <span className="sr-only">{done ? t("checkDone") : t("checkMissing")}: </span>
                        {t(`checklist.${item}`)}
                        {done ? null : (
                          <>
                            {" "}
                            <Link
                              href={`/studio/courses/${courseId}/${tab}`}
                              className="font-semibold underline"
                              aria-label={t("fixItNamed", { item: t(`checklist.${item}`) })}
                            >
                              {t("fixIt")}
                            </Link>
                          </>
                        )}
                      </span>
                    </li>
                  );
                })}
              </ul>
              <SubmitButton courseId={courseId} ready={d.checklist?.ready === true} />
            </>
          )}
        </Panel>
      ) : null}

      <Panel title={t("publishingTitle")}>
        <Lifecycle courseId={courseId} can={c.can} />
      </Panel>
    </div>
  );
}

function Panel({
  title,
  description,
  children,
}: {
  title: string;
  description?: string;
  children: ReactNode;
}) {
  return (
    <section className="grid gap-4 rounded-xl border bg-background p-4 sm:p-6">
      <div className="grid gap-1">
        <h2 className="text-lg font-bold">{title}</h2>
        {description ? <p className="text-sm text-muted-foreground">{description}</p> : null}
      </div>
      {children}
    </section>
  );
}

function ActionError({ error }: { error: unknown }) {
  const errorText = useErrorText();

  return error ? (
    <p role="alert" className="text-sm text-destructive">
      {errorText(error)}
    </p>
  ) : null;
}

function SubmitButton({ courseId, ready }: { courseId: string; ready: boolean }) {
  const t = useTranslations("web.studio");
  const refresh = useRefreshCourse(courseId);
  const submit = useMutation({ mutationFn: () => professorCoursesSubmit(courseId), onSuccess: refresh });

  return (
    <div className="grid gap-2">
      <div>
        <Button
          onClick={() => submit.mutate()}
          disabled={!ready || submit.isPending}
          aria-busy={submit.isPending}
        >
          {t("submit")}
        </Button>
      </div>
      {ready ? null : <p className="text-sm text-muted-foreground">{t("submitBlocked")}</p>}
      <ActionError error={submit.error} />
    </div>
  );
}

function InReview({ courseId, submittedAt }: { courseId: string; submittedAt: string | null }) {
  const t = useTranslations("web.studio");
  const locale = useLocale() as Locale;
  const refresh = useRefreshCourse(courseId);
  const withdraw = useMutation({ mutationFn: () => professorCoursesWithdraw(courseId), onSuccess: refresh });

  return (
    <div className="grid gap-3">
      <p>
        {submittedAt
          ? t("inReviewSince", { date: formatDateTime(submittedAt, locale) })
          : t("banner.in_review")}
      </p>
      <p className="text-sm text-muted-foreground">{t("withdrawHint")}</p>
      <div>
        <Button
          variant="outline"
          onClick={() => withdraw.mutate()}
          disabled={withdraw.isPending}
          aria-busy={withdraw.isPending}
        >
          {t("withdraw")}
        </Button>
      </div>
      <ActionError error={withdraw.error} />
    </div>
  );
}

/** Start a new draft, unpublish / republish, archive, delete — offered only when the API says they apply. */
function Lifecycle({ courseId, can }: { courseId: string; can: StudioCourseResourceCan }) {
  const t = useTranslations("web.studio");
  const common = useTranslations("common");
  const router = useRouter();
  const queryClient = useQueryClient();
  const refresh = useRefreshCourse(courseId);
  const action = useMutation({
    mutationFn: async (kind: "draft" | "unpublish" | "republish" | "archive" | "delete") => {
      const run = {
        draft: professorCoursesDraftStart,
        unpublish: professorCoursesUnpublish,
        republish: professorCoursesRepublish,
        archive: professorCoursesArchive,
        delete: professorCoursesDestroy,
      }[kind];
      await run(courseId);
      return kind;
    },
    onSuccess: async (kind) => {
      if (kind === "delete") {
        // The course is gone: drop its cached pages and reload the list before going back to it.
        queryClient.removeQueries({ queryKey: getProfessorCoursesShowQueryKey(courseId) });
        queryClient.removeQueries({ queryKey: getProfessorDraftShowQueryKey(courseId) });
        await queryClient.invalidateQueries({ queryKey: getProfessorCoursesIndexQueryKey() });
        router.push("/studio/courses");
        return;
      }
      await refresh();
      if (kind === "draft") router.push(`/studio/courses/${courseId}/details`);
    },
  });
  // ConfirmDialog stays open when onConfirm throws; the message shows under the actions.
  const confirm = (kind: "unpublish" | "archive" | "delete") => async () => {
    await action.mutateAsync(kind);
  };

  const items: ReactNode[] = [];

  if (can.start_draft) {
    items.push(
      <Action key="draft" hint={t("startDraftHint")}>
        <Button variant="outline" onClick={() => action.mutate("draft")} disabled={action.isPending}>
          {t("startDraft")}
        </Button>
      </Action>,
    );
  }
  if (can.unpublish) {
    items.push(
      <Action key="unpublish" hint={t("unpublishHint")}>
        <ConfirmDialog
          trigger={<Button variant="outline">{t("unpublish")}</Button>}
          title={t("unpublishConfirm")}
          description={t("unpublishHint")}
          confirmLabel={t("unpublish")}
          cancelLabel={common("cancel")}
          onConfirm={confirm("unpublish")}
        />
      </Action>,
    );
  }
  if (can.republish) {
    items.push(
      <Action key="republish" hint={t("republishHint")}>
        <Button variant="outline" onClick={() => action.mutate("republish")} disabled={action.isPending}>
          {t("republish")}
        </Button>
      </Action>,
    );
  }
  if (can.archive) {
    items.push(
      <Action key="archive" hint={t("archiveHint")}>
        <ConfirmDialog
          trigger={<Button variant="outline">{t("archive")}</Button>}
          title={t("archiveConfirm")}
          description={t("archiveHint")}
          confirmLabel={t("archive")}
          cancelLabel={common("cancel")}
          destructive
          onConfirm={confirm("archive")}
        />
      </Action>,
    );
  }
  if (can.delete) {
    items.push(
      <Action key="delete" hint={t("deleteHint")}>
        <ConfirmDialog
          trigger={<Button variant="destructive">{t("delete")}</Button>}
          title={t("deleteConfirm")}
          description={t("deleteHint")}
          confirmLabel={t("delete")}
          cancelLabel={common("cancel")}
          destructive
          onConfirm={confirm("delete")}
        />
      </Action>,
    );
  }

  return (
    <div className="grid gap-4">
      {items.length > 0 ? (
        <ul className="grid gap-4">{items}</ul>
      ) : (
        <p className="text-sm">{t("noActions")}</p>
      )}
      <ActionError error={action.error} />
    </div>
  );
}

function Action({ hint, children }: { hint: string; children: ReactNode }) {
  return (
    <li className="grid gap-1 sm:grid-cols-[12rem_1fr] sm:items-center sm:gap-4">
      <div>{children}</div>
      <p className="text-sm text-muted-foreground">{hint}</p>
    </li>
  );
}
