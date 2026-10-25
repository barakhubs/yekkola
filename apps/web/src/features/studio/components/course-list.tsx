"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { success } from "@yekkola/api-client";
import {
  getProfessorCoursesIndexQueryKey,
  professorCoursesStore,
  useProfessorCoursesIndex,
} from "@yekkola/api-client/endpoints/course";
import type { CreateCourseRequestLanguage } from "@yekkola/api-client/model";
import { formatDate, formatMoney, type Locale } from "@yekkola/i18n";
import { EmptyState } from "@yekkola/ui/blocks/empty-state";
import { PageHeader } from "@yekkola/ui/blocks/page-header";
import { StatusBadge } from "@yekkola/ui/blocks/status-badge";
import { Button } from "@yekkola/ui/components/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@yekkola/ui/components/dialog";
import { Input } from "@yekkola/ui/components/input";
import { Label } from "@yekkola/ui/components/label";
import { Skeleton } from "@yekkola/ui/components/skeleton";
import { BookOpen, Plus } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { parseAsInteger, useQueryState } from "nuqs";
import { type FormEvent, useState } from "react";

import { Link, useRouter } from "@/i18n/navigation";

import { fieldErrorsOf, useErrorText } from "../hooks";
import { selectClass } from "../styles";
import { COURSE_TONES, courseStatusOf } from "../status";

/** The professor's courses with their status, newest first, and a way to start a new one (PRD-03 FR-01). */
export function StudioCourseList() {
  const t = useTranslations("web.studio");
  const pagination = useTranslations("web.pagination");
  const errors = useTranslations("errors");
  const locale = useLocale() as Locale;
  const [page, setPage] = useQueryState("page", parseAsInteger.withDefault(1));
  const courses = useProfessorCoursesIndex({ page, per_page: 20 }, { query: { select: (r) => success(r) } });
  const lastPage = courses.data?.meta.last_page ?? 1;

  return (
    <div className="mx-auto grid max-w-5xl gap-6 px-4 py-8 sm:py-12">
      <PageHeader title={t("coursesTitle")} description={t("coursesIntro")} actions={<NewCourseDialog />} />

      {courses.isPending ? (
        <Skeleton className="h-48 w-full" />
      ) : courses.isError ? (
        <EmptyState title={errors("network")} />
      ) : courses.data.data.length === 0 ? (
        <EmptyState icon={<BookOpen />} title={t("noCourses")} description={t("noCoursesHint")} />
      ) : (
        <ul className="grid gap-3">
          {courses.data.data.map((course) => {
            const status = courseStatusOf(course.status);
            const draft = course.draft_version;

            return (
              <li
                key={course.id}
                className="grid gap-3 rounded-xl border bg-background p-4 sm:grid-cols-[1fr_auto]"
              >
                <div className="grid gap-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <StatusBadge status={status} tones={COURSE_TONES} label={t(`courseStatus.${status}`)} />
                    {draft && course.live_version ? (
                      <span className="text-xs text-muted-foreground">
                        {t(`draftState.${draft.state === "in_review" ? "in_review" : "draft"}`)}
                      </span>
                    ) : null}
                  </div>
                  <Link
                    href={`/studio/courses/${course.id}/details`}
                    className="text-lg font-bold hover:underline"
                  >
                    {course.title ?? t("untitled")}
                  </Link>
                  <p className="text-sm text-muted-foreground">
                    {[
                      course.is_free
                        ? t("free")
                        : course.price
                          ? formatMoney(course.price, locale)
                          : t("noPrice"),
                      t("enrollments", { count: course.enrollment_count }),
                      t("createdOn", { date: formatDate(course.created_at, locale) }),
                    ].join(" · ")}
                  </p>
                </div>
                <div className="flex items-center">
                  <Button asChild variant="outline">
                    <Link href={`/studio/courses/${course.id}/details`}>{t("edit")}</Link>
                  </Button>
                </div>
              </li>
            );
          })}
        </ul>
      )}

      {lastPage > 1 ? (
        <nav aria-label={pagination("label")} className="flex items-center justify-between gap-4">
          <Button variant="outline" disabled={page <= 1} onClick={() => setPage(page - 1)}>
            {pagination("previous")}
          </Button>
          <span className="text-sm text-muted-foreground">
            {pagination("page", { page, total: lastPage })}
          </span>
          <Button variant="outline" disabled={page >= lastPage} onClick={() => setPage(page + 1)}>
            {pagination("next")}
          </Button>
        </nav>
      ) : null}
    </div>
  );
}

/** Title and language are enough to start; everything else is filled in the editor. */
function NewCourseDialog() {
  const t = useTranslations("web.studio");
  const common = useTranslations("common");
  const locale = useLocale() as Locale;
  const router = useRouter();
  const queryClient = useQueryClient();
  const errorText = useErrorText();
  const [open, setOpen] = useState(false);
  const [title, setTitle] = useState("");
  const [language, setLanguage] = useState<CreateCourseRequestLanguage>(locale);

  const create = useMutation({
    mutationFn: async () => success(await professorCoursesStore({ title: title.trim(), language })).data,
    onSuccess: async (course) => {
      await queryClient.invalidateQueries({ queryKey: getProfessorCoursesIndexQueryKey() });
      router.push(`/studio/courses/${course.id}/details`);
    },
  });
  const fieldErrors = fieldErrorsOf(create.error);

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button>
          <Plus aria-hidden />
          {t("newCourse")}
        </Button>
      </DialogTrigger>
      <DialogContent closeLabel={common("close")}>
        <DialogHeader>
          <DialogTitle>{t("newCourse")}</DialogTitle>
          <DialogDescription>{t("newCourseHint")}</DialogDescription>
        </DialogHeader>
        <form
          className="grid gap-4"
          onSubmit={(e: FormEvent) => {
            e.preventDefault();
            if (!create.isPending) create.mutate();
          }}
        >
          <div className="grid gap-2">
            <Label htmlFor="new-course-title">{t("fields.title")}</Label>
            <Input
              id="new-course-title"
              value={title}
              maxLength={80}
              required
              aria-invalid={fieldErrors.title ? true : undefined}
              aria-describedby={fieldErrors.title ? "new-course-title-error" : undefined}
              onChange={(e) => setTitle(e.target.value)}
            />
            {fieldErrors.title ? (
              <p id="new-course-title-error" className="text-sm text-destructive">
                {fieldErrors.title}
              </p>
            ) : null}
          </div>
          <div className="grid gap-2">
            <Label htmlFor="new-course-language">{t("fields.language")}</Label>
            <select
              id="new-course-language"
              className={selectClass}
              value={language}
              onChange={(e) => setLanguage(e.target.value as CreateCourseRequestLanguage)}
            >
              <option value="fr">{common("languages.fr")}</option>
              <option value="en">{common("languages.en")}</option>
            </select>
          </div>
          {create.isError && Object.keys(fieldErrors).length === 0 ? (
            <p role="alert" className="text-sm text-destructive">
              {errorText(create.error)}
            </p>
          ) : null}
          <Button
            type="submit"
            disabled={create.isPending || title.trim().length < 5}
            aria-busy={create.isPending}
          >
            {t("create")}
          </Button>
        </form>
      </DialogContent>
    </Dialog>
  );
}
