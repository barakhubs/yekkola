"use client";

import { useMutation } from "@tanstack/react-query";
import { success } from "@yekkola/api-client";
import { useCategoriesIndex } from "@yekkola/api-client/endpoints/category";
import { professorCoursesUpdate } from "@yekkola/api-client/endpoints/course";
import { professorDraftCover, professorDraftUpdate } from "@yekkola/api-client/endpoints/draft";
import type {
  Audience,
  CourseLevel,
  CourseVersionResource,
  StudioCourseResource,
  UpdateCourseSettingsRequestLanguage,
} from "@yekkola/api-client/model";
import { Button } from "@yekkola/ui/components/button";
import { Input } from "@yekkola/ui/components/input";
import { Label } from "@yekkola/ui/components/label";
import { Skeleton } from "@yekkola/ui/components/skeleton";
import { Textarea } from "@yekkola/ui/components/textarea";
import { ImageUp } from "lucide-react";
import Image from "next/image";
import { useTranslations } from "next-intl";
import { type FormEvent, type ReactNode, useId, useState } from "react";

import {
  fieldErrorsOf,
  useAuthoringOptions,
  useErrorText,
  useRefreshCourse,
  useStudioCourse,
} from "../hooks";
import { selectClass } from "../styles";
import { DraftGate } from "./draft-gate";
import { ListField } from "./list-field";

/** Details tab: the draft's content (reviewed) and the course's settings (applied at once) — PRD-03 FR-02, FR-15. */
export function DetailsTab({ courseId }: { courseId: string }) {
  const course = useStudioCourse(courseId);

  return (
    <div className="grid gap-6">
      <DraftGate courseId={courseId}>
        {(draft, editable) => (
          <>
            <DraftDetailsForm
              key={`${draft.id}:${draft.number}`}
              courseId={courseId}
              draft={draft}
              editable={editable}
              hasLive={Boolean(course.data?.live_version)}
            />
            <CoverField courseId={courseId} coverUrl={draft.cover_url} editable={editable} />
          </>
        )}
      </DraftGate>
      {course.data ? <CourseSettingsForm key={course.data.id} course={course.data} /> : null}
    </div>
  );
}

function Section({
  title,
  description,
  children,
}: {
  title: string;
  description?: string;
  children: ReactNode;
}) {
  const id = useId();

  return (
    <section aria-labelledby={id} className="grid gap-4 rounded-xl border bg-background p-4 sm:p-6">
      <div className="grid gap-1">
        <h2 id={id} className="text-lg font-bold">
          {title}
        </h2>
        {description ? <p className="text-sm text-muted-foreground">{description}</p> : null}
      </div>
      {children}
    </section>
  );
}

function FieldError({ id, message }: { id: string; message?: string }) {
  return message ? (
    <p id={id} className="text-sm text-destructive">
      {message}
    </p>
  ) : null;
}

/** "Saved" after a successful save, the API's message after a failed one. */
function SaveStatus({ saved, error }: { saved: boolean; error: unknown }) {
  const t = useTranslations("web.studio");
  const errorText = useErrorText();
  const fieldErrors = fieldErrorsOf(error);

  if (error) {
    return (
      <p role="alert" className="text-sm text-destructive">
        {Object.keys(fieldErrors).length > 0 ? t("fixErrors") : errorText(error)}
      </p>
    );
  }

  return (
    <p role="status" className="text-sm text-muted-foreground">
      {saved ? t("saved") : ""}
    </p>
  );
}

function DraftDetailsForm({
  courseId,
  draft,
  editable,
  hasLive,
}: {
  courseId: string;
  draft: CourseVersionResource;
  editable: boolean;
  hasLive: boolean;
}) {
  const t = useTranslations("web.studio");
  const refresh = useRefreshCourse(courseId);
  const [title, setTitle] = useState(draft.title ?? "");
  const [subtitle, setSubtitle] = useState(draft.subtitle ?? "");
  const [description, setDescription] = useState(draft.description ?? "");
  const [outcomes, setOutcomes] = useState<string[]>(
    draft.outcomes.length > 0 ? draft.outcomes : ["", "", ""],
  );
  const [requirements, setRequirements] = useState<string[]>(draft.requirements);
  const [changelog, setChangelog] = useState(draft.changelog ?? "");
  const [dirty, setDirty] = useState(false);

  const save = useMutation({
    mutationFn: async () =>
      success(
        await professorDraftUpdate(courseId, {
          title: title.trim(),
          subtitle: subtitle.trim() || null,
          description: description.trim() || null,
          outcomes: outcomes.map((o) => o.trim()).filter(Boolean),
          requirements: requirements.map((r) => r.trim()).filter(Boolean),
          ...(hasLive ? { changelog: changelog.trim() || null } : {}),
        }),
      ),
    onSuccess: async () => {
      setDirty(false);
      await refresh();
    },
  });
  const errors = fieldErrorsOf(save.error);
  const edit =
    <T,>(set: (value: T) => void) =>
    (value: T) => {
      set(value);
      setDirty(true);
      if (save.isSuccess) save.reset();
    };

  return (
    <Section title={t("detailsTitle")} description={t("detailsIntro")}>
      <form
        className="grid gap-5"
        onSubmit={(e: FormEvent) => {
          e.preventDefault();
          if (!save.isPending) save.mutate();
        }}
      >
        <fieldset className="grid gap-5" disabled={!editable}>
          <div className="grid gap-2">
            <Label htmlFor="course-title">{t("fields.title")}</Label>
            <Input
              id="course-title"
              value={title}
              maxLength={80}
              aria-invalid={errors.title ? true : undefined}
              aria-describedby="course-title-hint course-title-error"
              onChange={(e) => edit(setTitle)(e.target.value)}
            />
            <p id="course-title-hint" className="text-xs text-muted-foreground">
              {t("counter", { count: title.length, max: 80 })}
            </p>
            <FieldError id="course-title-error" message={errors.title} />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="course-subtitle">{t("fields.subtitle")}</Label>
            <Input
              id="course-subtitle"
              value={subtitle}
              maxLength={120}
              aria-invalid={errors.subtitle ? true : undefined}
              aria-describedby="course-subtitle-error"
              onChange={(e) => edit(setSubtitle)(e.target.value)}
            />
            <FieldError id="course-subtitle-error" message={errors.subtitle} />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="course-description">{t("fields.description")}</Label>
            <Textarea
              id="course-description"
              value={description}
              rows={8}
              maxLength={10000}
              aria-invalid={errors.description ? true : undefined}
              aria-describedby="course-description-hint course-description-error"
              onChange={(e) => edit(setDescription)(e.target.value)}
            />
            <p id="course-description-hint" className="text-xs text-muted-foreground">
              {t("descriptionHint")}
            </p>
            <FieldError id="course-description-error" message={errors.description} />
          </div>
          <ListField
            name="outcomes"
            label={t("fields.outcomes")}
            hint={t("outcomesHint")}
            items={outcomes}
            max={8}
            errors={errors}
            onChange={edit(setOutcomes)}
          />
          <ListField
            name="requirements"
            label={t("fields.requirements")}
            hint={t("requirementsHint")}
            items={requirements}
            max={10}
            errors={errors}
            onChange={edit(setRequirements)}
          />
          {hasLive ? (
            <div className="grid gap-2">
              <Label htmlFor="course-changelog">{t("fields.changelog")}</Label>
              <Textarea
                id="course-changelog"
                value={changelog}
                rows={3}
                maxLength={2000}
                aria-describedby="course-changelog-hint course-changelog-error"
                onChange={(e) => edit(setChangelog)(e.target.value)}
              />
              <p id="course-changelog-hint" className="text-xs text-muted-foreground">
                {t("changelogHint")}
              </p>
              <FieldError id="course-changelog-error" message={errors.changelog} />
            </div>
          ) : null}
        </fieldset>
        {editable ? (
          <div className="flex flex-wrap items-center gap-3">
            <Button type="submit" disabled={!dirty || save.isPending} aria-busy={save.isPending}>
              {t("save")}
            </Button>
            <SaveStatus saved={save.isSuccess && !dirty} error={save.error} />
          </div>
        ) : null}
      </form>
    </Section>
  );
}

/** Cover image, 16:9, at least 1280×720 (PRD-03 FR-02); the API checks size and ratio. */
function CoverField({
  courseId,
  coverUrl,
  editable,
}: {
  courseId: string;
  coverUrl: string | null;
  editable: boolean;
}) {
  const t = useTranslations("web.studio");
  const errorText = useErrorText();
  const refresh = useRefreshCourse(courseId);
  const inputId = useId();

  const upload = useMutation({
    mutationFn: async (cover: File) => success(await professorDraftCover(courseId, { cover })),
    onSuccess: refresh,
  });
  const coverError = fieldErrorsOf(upload.error).cover;

  return (
    <Section title={t("coverTitle")} description={t("coverHint")}>
      <div className="grid gap-4 sm:grid-cols-[minmax(0,20rem)_1fr] sm:items-start">
        <div className="relative aspect-video overflow-hidden rounded-lg border bg-muted">
          {coverUrl ? (
            <Image src={coverUrl} alt={t("coverAlt")} fill sizes="20rem" className="object-cover" />
          ) : (
            <div className="grid size-full place-items-center text-muted-foreground">
              <ImageUp aria-hidden className="size-8" />
            </div>
          )}
        </div>
        {editable ? (
          <div className="grid gap-2">
            <Label htmlFor={inputId}>{coverUrl ? t("replaceCover") : t("chooseCover")}</Label>
            <Input
              id={inputId}
              type="file"
              accept="image/jpeg,image/png,image/webp"
              disabled={upload.isPending}
              aria-describedby={`${inputId}-status`}
              onChange={(e) => {
                const file = e.target.files?.[0];
                if (file) upload.mutate(file);
                e.target.value = "";
              }}
            />
            <div id={`${inputId}-status`}>
              {upload.isPending ? (
                <p role="status" className="text-sm text-muted-foreground">
                  {t("uploading")}
                </p>
              ) : upload.isError ? (
                <p role="alert" className="text-sm text-destructive">
                  {coverError ?? errorText(upload.error)}
                </p>
              ) : upload.isSuccess ? (
                <p role="status" className="text-sm text-muted-foreground">
                  {t("saved")}
                </p>
              ) : null}
            </div>
          </div>
        ) : null}
      </div>
    </Section>
  );
}

/** Category, language, level, audience: course settings, applied at once without review (PRD-03 FR-15). */
function CourseSettingsForm({ course }: { course: StudioCourseResource }) {
  const t = useTranslations("web.studio");
  const catalog = useTranslations("web.catalog");
  const common = useTranslations("common");
  const refresh = useRefreshCourse(course.id);
  const options = useAuthoringOptions();
  const categories = useCategoriesIndex({
    query: { select: (r) => success(r).data, staleTime: 10 * 60_000 },
  });
  const [categoryId, setCategoryId] = useState(course.category?.id ?? "");
  const [language, setLanguage] = useState(course.language);
  const [level, setLevel] = useState(course.level);
  const [audience, setAudience] = useState<string[]>(course.audience);
  const [dirty, setDirty] = useState(false);

  const save = useMutation({
    mutationFn: async () =>
      success(
        await professorCoursesUpdate(course.id, {
          category_id: categoryId || null,
          language: language as UpdateCourseSettingsRequestLanguage,
          level: level as CourseLevel,
          audience: audience as Audience[],
        }),
      ),
    onSuccess: async () => {
      setDirty(false);
      await refresh();
    },
  });
  const errors = fieldErrorsOf(save.error);
  const touched = () => {
    setDirty(true);
    if (save.isSuccess) save.reset();
  };

  if (options.isPending || categories.isPending) {
    return <Skeleton className="h-48 w-full" />;
  }

  return (
    <Section title={t("settingsTitle")} description={t("settingsIntro")}>
      <form
        className="grid gap-5"
        onSubmit={(e: FormEvent) => {
          e.preventDefault();
          if (!save.isPending) save.mutate();
        }}
      >
        <div className="grid gap-5 sm:grid-cols-3">
          <div className="grid gap-2">
            <Label htmlFor="course-category">{t("fields.category")}</Label>
            <select
              id="course-category"
              className={selectClass}
              value={categoryId}
              aria-invalid={errors.category_id ? true : undefined}
              aria-describedby="course-category-error"
              onChange={(e) => {
                setCategoryId(e.target.value);
                touched();
              }}
            >
              <option value="">{t("chooseCategory")}</option>
              {(categories.data ?? []).map((top) => (
                <optgroup key={top.id} label={top.name}>
                  <option value={top.id}>{top.name}</option>
                  {top.children.map((child) => (
                    <option key={child.id} value={child.id}>
                      {child.name}
                    </option>
                  ))}
                </optgroup>
              ))}
            </select>
            <FieldError id="course-category-error" message={errors.category_id} />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="course-language">{t("fields.language")}</Label>
            <select
              id="course-language"
              className={selectClass}
              value={language}
              onChange={(e) => {
                setLanguage(e.target.value);
                touched();
              }}
            >
              {(options.data?.languages ?? ["fr", "en"]).map((code) => (
                <option key={code} value={code}>
                  {common(`languages.${code as "fr" | "en"}`)}
                </option>
              ))}
            </select>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="course-level">{t("fields.level")}</Label>
            <select
              id="course-level"
              className={selectClass}
              value={level}
              onChange={(e) => {
                setLevel(e.target.value);
                touched();
              }}
            >
              {(options.data?.levels ?? []).map((value) => (
                <option key={value} value={value}>
                  {catalog(`levels.${value as CourseLevel}`)}
                </option>
              ))}
            </select>
          </div>
        </div>
        <fieldset className="grid gap-2">
          <legend className="mb-1 text-sm font-semibold">{t("fields.audience")}</legend>
          <p className="-mt-1 text-xs text-muted-foreground">{t("audienceHint")}</p>
          <div className="flex flex-wrap gap-x-6 gap-y-2">
            {(options.data?.audiences ?? []).map((value) => (
              <label key={value} className="flex items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  className="size-4 accent-primary"
                  checked={audience.includes(value)}
                  onChange={(e) => {
                    setAudience(
                      e.target.checked ? [...audience, value] : audience.filter((a) => a !== value),
                    );
                    touched();
                  }}
                />
                {catalog(`audiences.${value as Audience}`)}
              </label>
            ))}
          </div>
          <FieldError id="course-audience-error" message={errors.audience} />
        </fieldset>
        <div className="flex flex-wrap items-center gap-3">
          <Button type="submit" disabled={!dirty || save.isPending} aria-busy={save.isPending}>
            {t("save")}
          </Button>
          <SaveStatus saved={save.isSuccess && !dirty} error={save.error} />
        </div>
      </form>
    </Section>
  );
}
