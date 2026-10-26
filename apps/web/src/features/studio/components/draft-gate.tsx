"use client";

import { useMutation } from "@tanstack/react-query";
import { ApiError } from "@yekkola/api-client";
import { professorCoursesDraftStart } from "@yekkola/api-client/endpoints/course";
import type { CourseVersionResource } from "@yekkola/api-client/model";
import { EmptyState } from "@yekkola/ui/blocks/empty-state";
import { Button } from "@yekkola/ui/components/button";
import { Skeleton } from "@yekkola/ui/components/skeleton";
import { FilePenLine, Lock } from "lucide-react";
import { useTranslations } from "next-intl";
import type { ReactNode } from "react";

import { useErrorText, useRefreshCourse, useStudioDraft } from "../hooks";

/**
 * Loads the draft for the Details and Curriculum tabs. A live course without a draft offers "Start a new draft"
 * (PRD-03 FR-13: copies the live version, lessons keep their uid). A draft in review is shown read-only (FR-11).
 */
export function DraftGate({
  courseId,
  children,
}: {
  courseId: string;
  children: (draft: CourseVersionResource, editable: boolean) => ReactNode;
}) {
  const t = useTranslations("web.studio");
  const errorText = useErrorText();
  const refresh = useRefreshCourse(courseId);
  const draft = useStudioDraft(courseId);

  const start = useMutation({
    mutationFn: () => professorCoursesDraftStart(courseId),
    onSuccess: refresh,
  });

  if (draft.isPending) {
    return <Skeleton className="h-64 w-full" />;
  }

  if (draft.isError) {
    const noDraft = draft.error instanceof ApiError && draft.error.code === "course.no_draft";
    return (
      <EmptyState
        icon={noDraft ? <FilePenLine /> : undefined}
        title={noDraft ? t("noDraftTitle") : errorText(draft.error)}
        description={noDraft ? t("noDraftBody") : undefined}
        action={
          noDraft ? (
            <div className="grid justify-items-center gap-2">
              <Button onClick={() => start.mutate()} disabled={start.isPending} aria-busy={start.isPending}>
                {t("startDraft")}
              </Button>
              {start.isError ? (
                <p role="alert" className="text-sm text-destructive">
                  {errorText(start.error)}
                </p>
              ) : null}
            </div>
          ) : undefined
        }
      />
    );
  }

  const editable = draft.data.is_editable;

  return (
    <div className="grid gap-6">
      {editable ? null : (
        <p className="flex items-start gap-2 rounded-lg border bg-background p-3 text-sm">
          <Lock aria-hidden className="mt-0.5 size-4 shrink-0" />
          {t("readOnly")}
        </p>
      )}
      {children(draft.data, editable)}
    </div>
  );
}
