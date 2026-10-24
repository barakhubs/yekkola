import type { CourseDetailResourceSectionsItem } from "@yekkola/api-client/model";
import { formatBytes, formatDuration, type Locale } from "@yekkola/i18n";
import { FileText, Headphones, ListChecks, PlayCircle } from "lucide-react";
import { getTranslations } from "next-intl/server";

import { PreviewButton } from "./preview-button";

const ICONS = { video: PlayCircle, audio: Headphones, document: FileText, quiz: ListChecks } as const;
type LessonType = keyof typeof ICONS;

/**
 * Sections and lessons with type, duration, download size and preview (PRD-04 FR-05). Audio and documents
 * are flagged as the low-data option (project-context: data cost is the real constraint).
 */
export async function Curriculum({
  sections,
  locale,
}: {
  sections: CourseDetailResourceSectionsItem[];
  locale: Locale;
}) {
  const t = await getTranslations("web.catalog");

  return (
    <ol className="grid gap-4">
      {sections.map((section, index) => (
        <li key={section.id} className="overflow-hidden rounded-lg border">
          <details open={index === 0} className="group">
            <summary className="flex cursor-pointer list-none items-center justify-between gap-4 bg-muted px-4 py-3 font-bold">
              <span>{section.title}</span>
              <span className="shrink-0 text-sm font-normal text-muted-foreground">
                {t("lessons", { count: section.lessons.length })}
              </span>
            </summary>
            <ul className="divide-y">
              {section.lessons.map((lesson) => {
                const type = (lesson.type in ICONS ? lesson.type : "document") as LessonType;
                const Icon = ICONS[type];
                const playable = (type === "video" || type === "audio") && lesson.is_preview;

                return (
                  <li
                    key={lesson.uid}
                    className="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 text-sm"
                  >
                    <Icon
                      aria-label={t(`lessonTypes.${type}`)}
                      className="size-4 shrink-0 text-muted-foreground"
                    />
                    <span className="min-w-0 flex-1">{lesson.title}</span>
                    {type === "audio" || type === "document" ? (
                      <span className="rounded bg-indigo-50 px-1.5 py-0.5 text-xs font-semibold text-indigo-800 dark:bg-indigo-950 dark:text-indigo-200">
                        {t("lowData")}
                      </span>
                    ) : null}
                    {playable ? (
                      <PreviewButton lessonUid={lesson.uid} title={lesson.title} kind={type} compact />
                    ) : null}
                    {lesson.duration_seconds ? (
                      <span className="text-muted-foreground tabular-nums">
                        {formatDuration(lesson.duration_seconds, locale)}
                      </span>
                    ) : null}
                    {lesson.download_bytes > 0 ? (
                      <span className="text-xs text-muted-foreground tabular-nums">
                        {formatBytes(lesson.download_bytes, locale)}
                      </span>
                    ) : null}
                  </li>
                );
              })}
            </ul>
          </details>
        </li>
      ))}
    </ol>
  );
}
