"use client";

import { useMutation } from "@tanstack/react-query";
import { success } from "@yekkola/api-client";
import {
  professorDraftLessonsDestroy,
  professorDraftLessonsUpdate,
} from "@yekkola/api-client/endpoints/draft";
import type { StudioLessonResource } from "@yekkola/api-client/model";
import { ConfirmDialog } from "@yekkola/ui/blocks/confirm-dialog";
import { Button } from "@yekkola/ui/components/button";
import { Input } from "@yekkola/ui/components/input";
import { Label } from "@yekkola/ui/components/label";
import { Skeleton } from "@yekkola/ui/components/skeleton";
import { Textarea } from "@yekkola/ui/components/textarea";
import { Trash2 } from "lucide-react";
import { useTranslations } from "next-intl";
import { type FormEvent, useEffect, useId, useRef, useState } from "react";

import { fieldErrorsOf, useAuthoringOptions, useErrorText, useRefreshCourse } from "../hooks";
import { DocumentContent, MediaContent } from "./lesson-content";
import { useUnsavedChanges } from "../unsaved";
import { QuizBuilder } from "./quiz-builder";

/** One lesson: title, description, free preview (PRD-03 FR-08), and its content by type (FR-04, FR-06, FR-07). */
export function LessonEditor({
  courseId,
  lesson,
  editable,
  focusOnMount = false,
  onDeleted,
}: {
  courseId: string;
  lesson: StudioLessonResource;
  editable: boolean;
  /** A lesson just added: start typing its details straight away. */
  focusOnMount?: boolean;
  onDeleted: () => void;
}) {
  const t = useTranslations("web.studio");
  const common = useTranslations("common");
  const errorText = useErrorText();
  const refresh = useRefreshCourse(courseId);
  const options = useAuthoringOptions();
  const id = useId();
  const [title, setTitle] = useState(lesson.title);
  const [description, setDescription] = useState(lesson.description ?? "");
  const [preview, setPreview] = useState(lesson.is_preview);
  const [dirty, setDirty] = useState(false);
  const titleInput = useRef<HTMLInputElement>(null);
  useUnsavedChanges(dirty);

  useEffect(() => {
    if (focusOnMount) titleInput.current?.focus();
    // Only when the editor first appears.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const save = useMutation({
    mutationFn: async () =>
      success(
        await professorDraftLessonsUpdate(courseId, lesson.id, {
          title: title.trim(),
          description: description.trim() || null,
          is_preview: preview,
        }),
      ),
    onSuccess: async () => {
      setDirty(false);
      await refresh();
    },
  });
  const remove = useMutation({
    mutationFn: () => professorDraftLessonsDestroy(courseId, lesson.id),
    onSuccess: async () => {
      onDeleted();
      await refresh();
    },
  });
  const errors = fieldErrorsOf(save.error);
  const touched = () => {
    setDirty(true);
    if (save.isSuccess) save.reset();
  };

  return (
    <div className="grid gap-5 border-t bg-muted/30 p-3 sm:p-4">
      <form
        className="grid gap-4"
        onSubmit={(e: FormEvent) => {
          e.preventDefault();
          if (!save.isPending) save.mutate();
        }}
      >
        <fieldset className="grid gap-4" disabled={!editable}>
          <legend className="sr-only">{t("lessonBasics")}</legend>
          <div className="grid gap-2">
            <Label htmlFor={`${id}-title`}>{t("fields.lessonTitle")}</Label>
            <Input
              ref={titleInput}
              id={`${id}-title`}
              value={title}
              maxLength={120}
              aria-invalid={errors.title ? true : undefined}
              onChange={(e) => {
                setTitle(e.target.value);
                touched();
              }}
            />
            {errors.title ? <p className="text-sm text-destructive">{errors.title}</p> : null}
          </div>
          <div className="grid gap-2">
            <Label htmlFor={`${id}-description`}>{t("fields.lessonDescription")}</Label>
            <Textarea
              id={`${id}-description`}
              rows={3}
              maxLength={5000}
              value={description}
              onChange={(e) => {
                setDescription(e.target.value);
                touched();
              }}
            />
          </div>
          <label className="flex items-start gap-2 text-sm">
            <input
              type="checkbox"
              className="mt-0.5 size-4 accent-primary"
              checked={preview}
              aria-describedby={`${id}-preview-hint`}
              onChange={(e) => {
                setPreview(e.target.checked);
                touched();
              }}
            />
            <span className="grid">
              <span className="font-semibold">{t("fields.preview")}</span>
              <span id={`${id}-preview-hint`} className="text-xs text-muted-foreground">
                {options.data ? t("previewHint", { max: options.data.max_preview_lessons }) : null}
              </span>
            </span>
          </label>
        </fieldset>
        {editable ? (
          <div className="flex flex-wrap items-center gap-3">
            <Button type="submit" size="sm" disabled={!dirty || save.isPending} aria-busy={save.isPending}>
              {t("save")}
            </Button>
            {save.isError ? (
              <p role="alert" className="text-sm text-destructive">
                {Object.keys(errors).length > 0 ? t("fixErrors") : errorText(save.error)}
              </p>
            ) : (
              <p role="status" className="text-sm text-muted-foreground">
                {save.isSuccess && !dirty ? t("saved") : ""}
              </p>
            )}
          </div>
        ) : null}
      </form>

      <section aria-labelledby={`${id}-content`} className="grid gap-3">
        <h4 id={`${id}-content`} className="text-sm font-bold">
          {t(`contentTitle.${lesson.type as "video" | "audio" | "document" | "quiz"}`)}
        </h4>
        {options.isPending ? (
          <Skeleton className="h-20 w-full" />
        ) : options.isError ? (
          <p role="alert" className="text-sm text-destructive">
            {errorText(options.error)}
          </p>
        ) : lesson.type === "video" || lesson.type === "audio" ? (
          <MediaContent courseId={courseId} lesson={lesson} editable={editable} />
        ) : lesson.type === "document" ? (
          <DocumentContent courseId={courseId} lesson={lesson} editable={editable} />
        ) : (
          <QuizBuilder courseId={courseId} lesson={lesson} editable={editable} />
        )}
      </section>

      {editable ? (
        <div className="grid gap-2 border-t pt-4">
          <div>
            <ConfirmDialog
              trigger={
                <Button variant="ghost" size="sm" className="text-destructive">
                  <Trash2 aria-hidden />
                  {t("deleteLesson")}
                </Button>
              }
              title={t("deleteLessonConfirm", { title: lesson.title })}
              description={t("deleteLessonHint")}
              confirmLabel={t("deleteLesson")}
              cancelLabel={common("cancel")}
              destructive
              onConfirm={async () => {
                await remove.mutateAsync();
              }}
            />
          </div>
          {remove.isError ? (
            <p role="alert" className="text-sm text-destructive">
              {errorText(remove.error)}
            </p>
          ) : null}
        </div>
      ) : null}
    </div>
  );
}
