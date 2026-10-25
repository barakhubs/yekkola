"use client";

import { ApiError } from "@yekkola/api-client";
import { EmptyState } from "@yekkola/ui/blocks/empty-state";
import { StatusBadge } from "@yekkola/ui/blocks/status-badge";
import { Skeleton } from "@yekkola/ui/components/skeleton";
import { cn } from "@yekkola/ui/lib/utils";
import { ArrowLeft, Info } from "lucide-react";
import { useTranslations } from "next-intl";
import type { MouseEvent, ReactNode } from "react";

import { Link, usePathname } from "@/i18n/navigation";

import { useErrorText, useStudioCourse } from "../hooks";
import { bannerOf, COURSE_TONES, courseStatusOf } from "../status";
import { hasUnsavedChanges } from "../unsaved";

const TABS = ["details", "curriculum", "pricing", "review"] as const;

/**
 * Course editor frame (PRD-03 §6): title, status, a banner saying what students see and where the draft stands,
 * and the tabs. Each tab loads its own data; this frame only needs the course.
 */
export function CourseEditorShell({ courseId, children }: { courseId: string; children: ReactNode }) {
  const t = useTranslations("web.studio");
  const errorText = useErrorText();
  const pathname = usePathname();
  const course = useStudioCourse(courseId);

  if (course.isPending) {
    return (
      <div className="mx-auto grid max-w-5xl gap-4 px-4 py-8">
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }

  if (course.isError) {
    const missing = course.error instanceof ApiError && course.error.status === 404;
    return (
      <div className="mx-auto max-w-xl px-4 py-16">
        <EmptyState title={missing ? t("courseNotFound") : errorText(course.error)} />
      </div>
    );
  }

  const c = course.data;
  const status = courseStatusOf(c.status);
  const confirmLeave = (event: MouseEvent) => {
    if (hasUnsavedChanges() && !window.confirm(t("unsavedConfirm"))) event.preventDefault();
  };
  const banner = bannerOf(c);

  return (
    <div className="mx-auto grid max-w-5xl gap-6 px-4 py-6 sm:py-10">
      <div className="grid gap-3">
        <Link
          href="/studio/courses"
          onClick={confirmLeave}
          className="inline-flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
        >
          <ArrowLeft aria-hidden className="size-4" />
          {t("coursesTitle")}
        </Link>
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="text-2xl font-extrabold sm:text-3xl">{c.title ?? t("untitled")}</h1>
          <StatusBadge status={status} tones={COURSE_TONES} label={t(`courseStatus.${status}`)} />
        </div>
        <p
          className={cn(
            "flex items-start gap-2 rounded-lg border p-3 text-sm",
            banner === "rejected" ? "border-destructive/40 bg-destructive/5" : "bg-background",
          )}
        >
          <Info aria-hidden className="mt-0.5 size-4 shrink-0" />
          <span>
            {t(`banner.${banner}`)}
            {banner === "rejected" ? (
              <>
                {" "}
                <Link href={`/studio/courses/${courseId}/review`} className="font-semibold underline">
                  {t("seeNotes")}
                </Link>
              </>
            ) : null}
          </span>
        </p>
      </div>

      <nav aria-label={t("editorTabs")} className="overflow-x-auto border-b">
        <ul className="flex min-w-max gap-1">
          {TABS.map((tab) => {
            const href = `/studio/courses/${courseId}/${tab}`;
            const current = pathname === href;
            return (
              <li key={tab}>
                <Link
                  href={href}
                  onClick={current ? undefined : confirmLeave}
                  aria-current={current ? "page" : undefined}
                  className={cn(
                    "-mb-px inline-block border-b-2 px-4 py-2 text-sm font-semibold",
                    current
                      ? "border-primary text-primary"
                      : "border-transparent text-muted-foreground hover:text-foreground",
                  )}
                >
                  {t(`tabs.${tab}`)}
                </Link>
              </li>
            );
          })}
        </ul>
      </nav>

      {children}
    </div>
  );
}
