"use client";

import { useQueryClient } from "@tanstack/react-query";
import { ApiError, success } from "@yekkola/api-client";
import { getMeCoursesShowQueryKey, useMeCoursesShow } from "@yekkola/api-client/endpoints/learning";
import type {
  LearningViewResource,
  LearningViewResourceSectionsItemLessonsItem,
} from "@yekkola/api-client/model";
import { formatDuration, type Locale } from "@yekkola/i18n";
import { EmptyState } from "@yekkola/ui/blocks/empty-state";
import { Button } from "@yekkola/ui/components/button";
import { Skeleton } from "@yekkola/ui/components/skeleton";
import { cn } from "@yekkola/ui/lib/utils";
import {
  CheckCircle2,
  ChevronLeft,
  ChevronRight,
  Circle,
  CircleDot,
  FileText,
  Headphones,
  ListChecks,
  PlayCircle,
} from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";

import { Link, useRouter } from "@/i18n/navigation";

import { useProgressSync } from "../hooks/use-progress-sync";
import { CourseComplete } from "./course-complete";
import { DocumentLesson } from "./document-lesson";
import { MediaLesson } from "./media-lesson";
import { QuizLesson } from "./quiz-lesson";

type Lesson = LearningViewResourceSectionsItemLessonsItem;

const TYPE_ICONS = { video: PlayCircle, audio: Headphones, document: FileText, quiz: ListChecks } as const;

interface LearningViewProps {
  courseId: string;
  /** From the URL; defaults to where the student left off. */
  lessonUid?: string;
}

/**
 * The learning view (PRD-06 FR-02): curriculum with my status per lesson, the current lesson, previous/next,
 * and progress saved as I go (FR-05). Completion rules are the API's; this only reports positions.
 */
export function LearningView({ courseId, lessonUid }: LearningViewProps) {
  const t = useTranslations("web.learn");
  const errors = useTranslations("errors");
  const common = useTranslations("common");
  const nav = useTranslations("web.nav");
  const queryClient = useQueryClient();
  const view = useMeCoursesShow(courseId, { query: { select: (r) => success(r).data, retry: false } });
  const refreshAfterSync = useRef(false);

  const refresh = useCallback(
    () => queryClient.invalidateQueries({ queryKey: getMeCoursesShowQueryKey(courseId) }),
    [queryClient, courseId],
  );
  const progress = useProgressSync(
    useCallback(() => {
      if (refreshAfterSync.current) {
        refreshAfterSync.current = false;
        void refresh();
      }
    }, [refresh]),
  );

  if (view.isPending) {
    return (
      <div className="mx-auto grid max-w-6xl gap-4 px-4 py-8 lg:grid-cols-[1fr_20rem]">
        <Skeleton className="aspect-video w-full" />
        <Skeleton className="h-96 w-full" />
      </div>
    );
  }

  if (view.isError) {
    const notEnrolled = view.error instanceof ApiError && view.error.status === 403;
    return (
      <div className="mx-auto max-w-xl px-4 py-16">
        <EmptyState
          title={notEnrolled ? (view.error.body.message ?? errors("forbidden")) : t("lessonError")}
          action={
            notEnrolled ? (
              <Button asChild>
                <Link href="/my-courses">{nav("myCourses")}</Link>
              </Button>
            ) : (
              <Button variant="outline" onClick={() => view.refetch()}>
                {common("retry")}
              </Button>
            )
          }
        />
      </div>
    );
  }

  return (
    <LessonLayout
      view={view.data}
      lessonUid={lessonUid}
      onPosition={(lesson, seconds, final) => {
        progress.record({ lesson_uid: lesson, position_seconds: Math.floor(seconds) });
        if (final) {
          refreshAfterSync.current = true;
          void progress.flush();
        }
      }}
      onDocumentDone={(lesson) => {
        progress.record({ lesson_uid: lesson, completed: true });
        refreshAfterSync.current = true;
        void progress.flush();
      }}
      onQuizSubmitted={() => void refresh()}
    />
  );
}

interface LessonLayoutProps {
  view: LearningViewResource;
  lessonUid?: string;
  onPosition: (lessonUid: string, seconds: number, final: boolean) => void;
  onDocumentDone: (lessonUid: string) => void;
  onQuizSubmitted: () => void;
}

function LessonLayout({ view, lessonUid, onPosition, onDocumentDone, onQuizSubmitted }: LessonLayoutProps) {
  const t = useTranslations("web.learn");
  const locale = useLocale() as Locale;
  const [curriculumOpen, setCurriculumOpen] = useState(false);
  const lessons = useMemo(() => view.sections.flatMap((s) => s.lessons), [view.sections]);
  const requested = lessons.findIndex(
    (l) => l.uid === (lessonUid ?? view.continue_lesson_uid ?? view.next_lesson_uid),
  );
  // A lesson removed in a newer version (or a bad link): show the course from where to continue.
  const unknownLesson = lessonUid !== undefined && requested === -1;
  const index = Math.max(
    0,
    unknownLesson ? lessons.findIndex((l) => l.uid === view.continue_lesson_uid) : requested,
  );
  const router = useRouter();
  useEffect(() => {
    if (unknownLesson) router.replace(`/learn/${view.course.id}`);
  }, [unknownLesson, router, view.course.id]);
  const lesson = lessons[index];
  const previous = lessons[index - 1];
  const next = lessons[index + 1];
  const base = `/learn/${view.course.id}`;

  if (!lesson) {
    return (
      <div className="mx-auto max-w-xl px-4 py-16">
        <EmptyState title={t("lessonError")} />
      </div>
    );
  }

  return (
    <div className="mx-auto grid max-w-7xl gap-6 px-4 py-6 lg:grid-cols-[1fr_22rem]">
      <div className="grid content-start gap-5">
        <header className="grid gap-2">
          <Link
            href={`/courses/${view.course.slug}`}
            className="w-fit text-sm font-semibold text-primary hover:underline"
          >
            {view.course.title}
          </Link>
          <h1 className="text-2xl font-extrabold">{lesson.title}</h1>
          <ProgressBar pct={view.progress_pct} />
        </header>

        {view.completed_at ? (
          <CourseComplete
            courseId={view.course.id}
            certificateSerial={view.certificate?.serial ?? null}
            hasReviewed={view.has_reviewed}
          />
        ) : null}

        <LessonContent
          key={lesson.uid}
          lesson={lesson}
          onPosition={onPosition}
          onDocumentDone={onDocumentDone}
          onQuizSubmitted={onQuizSubmitted}
        />

        <nav aria-label={t("curriculum")} className="flex items-center justify-between gap-3">
          {previous ? (
            <Button asChild variant="outline">
              <Link href={`${base}/${previous.uid}`}>
                <ChevronLeft aria-hidden />
                {t("previous")}
              </Link>
            </Button>
          ) : (
            <span />
          )}
          {next ? (
            <Button asChild>
              <Link href={`${base}/${next.uid}`}>
                {t("next")}
                <ChevronRight aria-hidden />
              </Link>
            </Button>
          ) : null}
        </nav>
      </div>

      <aside
        aria-labelledby="curriculum-heading"
        className="grid content-start gap-3 lg:sticky lg:top-4 lg:max-h-[calc(100dvh-2rem)] lg:overflow-y-auto"
      >
        <div className="flex items-center justify-between">
          <h2 id="curriculum-heading" className="text-lg font-bold">
            {t("curriculum")}
          </h2>
          <Button
            variant="ghost"
            size="sm"
            className="lg:hidden"
            aria-expanded={curriculumOpen}
            aria-controls="curriculum-list"
            onClick={() => setCurriculumOpen((open) => !open)}
          >
            {curriculumOpen ? t("hideCurriculum") : t("showCurriculum")}
          </Button>
        </div>
        <ol id="curriculum-list" className={cn("grid gap-4", !curriculumOpen && "hidden lg:grid")}>
          {view.sections.map((section) => (
            <li key={section.id} className="grid gap-1">
              <h3 className="text-sm font-bold text-muted-foreground">{section.title}</h3>
              <ul className="grid gap-1">
                {section.lessons.map((item) => {
                  const Icon = TYPE_ICONS[item.type as keyof typeof TYPE_ICONS] ?? FileText;
                  const current = item.uid === lesson.uid;
                  return (
                    <li key={item.uid}>
                      <Link
                        href={`${base}/${item.uid}`}
                        aria-current={current ? "page" : undefined}
                        className={cn(
                          "flex items-center gap-2 rounded-md px-2 py-2 text-sm hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none",
                          current && "bg-accent font-semibold text-accent-foreground",
                        )}
                      >
                        <StatusIcon status={item.status} label={t(`status.${statusKey(item.status)}`)} />
                        <Icon aria-hidden className="size-4 shrink-0 text-muted-foreground" />
                        <span className="min-w-0 flex-1">{item.title}</span>
                        {item.duration_seconds ? (
                          <span className="text-xs text-muted-foreground tabular-nums">
                            {formatDuration(item.duration_seconds, locale)}
                          </span>
                        ) : null}
                      </Link>
                    </li>
                  );
                })}
              </ul>
            </li>
          ))}
        </ol>
      </aside>
    </div>
  );
}

function LessonContent({
  lesson,
  onPosition,
  onDocumentDone,
  onQuizSubmitted,
}: {
  lesson: Lesson;
} & Omit<LessonLayoutProps, "view" | "lessonUid">) {
  if (lesson.type === "video" || lesson.type === "audio") {
    return (
      <MediaLesson
        lessonUid={lesson.uid}
        title={lesson.title}
        startAt={lesson.status === "completed" ? 0 : lesson.position_seconds}
        onPosition={(seconds, final) => onPosition(lesson.uid, seconds, final)}
      />
    );
  }
  if (lesson.type === "document") {
    return (
      <DocumentLesson
        lessonUid={lesson.uid}
        title={lesson.title}
        completed={lesson.status === "completed"}
        onDone={() => onDocumentDone(lesson.uid)}
      />
    );
  }
  if (lesson.type === "quiz" && lesson.quiz) {
    return <QuizLesson lessonUid={lesson.uid} facts={lesson.quiz} onSubmitted={onQuizSubmitted} />;
  }

  return null;
}

function statusKey(status: string): "not_started" | "in_progress" | "completed" {
  return status === "completed" || status === "in_progress" ? status : "not_started";
}

function StatusIcon({ status, label }: { status: string; label: string }) {
  if (status === "completed")
    return <CheckCircle2 aria-label={label} className="size-4 shrink-0 text-success" />;
  if (status === "in_progress")
    return <CircleDot aria-label={label} className="size-4 shrink-0 text-primary" />;
  return <Circle aria-label={label} className="size-4 shrink-0 text-muted-foreground" />;
}

function ProgressBar({ pct }: { pct: number }) {
  const t = useTranslations("web.learn");

  return (
    <div className="grid gap-1">
      <div
        className="h-2 overflow-hidden rounded-full bg-muted"
        role="progressbar"
        aria-valuemin={0}
        aria-valuemax={100}
        aria-valuenow={pct}
        aria-label={t("progress", { pct })}
      >
        <div className="h-full rounded-full bg-primary transition-[width]" style={{ width: `${pct}%` }} />
      </div>
      <p className="text-xs text-muted-foreground">{t("progress", { pct })}</p>
    </div>
  );
}
