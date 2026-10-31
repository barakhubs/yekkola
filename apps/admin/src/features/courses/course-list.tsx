"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { success } from "@yekkola/api-client";
import {
  adminCoursesRepublish,
  adminCoursesRestore,
  adminCoursesTakeDown,
  adminCoursesUnpublish,
  getAdminCoursesIndexQueryKey,
  useAdminCoursesIndex,
} from "@yekkola/api-client/endpoints/course";
import {
  adminFeaturedCoursesUpdate,
  getAdminFeaturedCoursesIndexQueryKey,
  useAdminFeaturedCoursesIndex,
} from "@yekkola/api-client/endpoints/featured-course";
import type { AdminCourseResource } from "@yekkola/api-client/model";
import { formatDateTime, formatMoney, type Locale } from "@yekkola/i18n";
import { ConfirmDialog } from "@yekkola/ui/blocks/confirm-dialog";
import { DataTable, dataTableColumns } from "@yekkola/ui/blocks/data-table";
import { PageHeader } from "@yekkola/ui/blocks/page-header";
import { StatusBadge } from "@yekkola/ui/blocks/status-badge";
import { Badge } from "@yekkola/ui/components/badge";
import { Button } from "@yekkola/ui/components/button";
import { Input } from "@yekkola/ui/components/input";
import { Label } from "@yekkola/ui/components/label";
import { ArrowDown, ArrowUp, Star, X } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { parseAsInteger, parseAsString, useQueryState } from "nuqs";
import { type FormEvent, useState } from "react";

import { useSession } from "@/features/auth/session";
import { useErrorText } from "@/features/common/errors";
import { selectClass } from "@/features/common/styles";
import { fromSort, toSort, useTableLabels } from "@/features/common/table";

import { COURSE_STATES, COURSE_TONES, courseStateOf } from "./status";

const col = dataTableColumns<AdminCourseResource>();

type Action = "unpublish" | "republish" | "take_down" | "restore";
const ACTIONS: Record<Action, (id: string, body: { note: string }) => Promise<unknown>> = {
  unpublish: adminCoursesUnpublish,
  republish: adminCoursesRepublish,
  take_down: adminCoursesTakeDown,
  restore: adminCoursesRestore,
};

/**
 * Courses (PRD-09 FR-08): find any course; unpublish (enrolled students keep access) or take down (closed to
 * everyone, enrolled students included), each with a reason; and the featured list on the home page.
 */
export function CourseList() {
  const t = useTranslations("admin.courses");
  const common = useTranslations("common");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const labels = useTableLabels();
  const session = useSession();
  const canManage = session.can("catalog.manage");
  const queryClient = useQueryClient();
  const [search, setSearch] = useQueryState("q", parseAsString.withDefault(""));
  const [status, setStatus] = useQueryState("status", parseAsString.withDefault("all"));
  const [sort, setSort] = useQueryState("sort", parseAsString);
  const [page, setPage] = useQueryState("page", parseAsInteger.withDefault(1));
  const [draft, setDraft] = useState(search);

  const courses = useAdminCoursesIndex(
    {
      page,
      per_page: 25,
      ...(search ? { "filter[q]": search } : {}),
      ...(status === "taken_down"
        ? { "filter[taken_down]": "1" }
        : status === "all"
          ? {}
          : { "filter[status]": status }),
      ...(sort ? { sort } : {}),
    },
    { query: { select: (r) => success(r), placeholderData: (previous) => previous } },
  );
  const featured = useAdminFeaturedCoursesIndex({
    query: { enabled: canManage, select: (r) => success(r).data },
  });
  const featuredIds = (featured.data ?? []).map((c) => c.id);
  const refresh = () =>
    Promise.all([
      queryClient.invalidateQueries({ queryKey: getAdminCoursesIndexQueryKey() }),
      queryClient.invalidateQueries({ queryKey: getAdminFeaturedCoursesIndexQueryKey() }),
    ]);

  const act = useMutation({
    mutationFn: ({ action, id, note }: { action: Action; id: string; note: string }) =>
      ACTIONS[action](id, { note }),
    onSettled: refresh,
  });
  const setFeatured = useMutation({
    mutationFn: (ids: string[]) => adminFeaturedCoursesUpdate({ course_ids: ids }),
    onSettled: refresh,
  });

  const actionDialog = (course: AdminCourseResource, action: Action) => (
    <ConfirmDialog
      key={action}
      trigger={
        <Button size="sm" variant={action === "take_down" ? "destructive" : "outline"}>
          {t(`actions.${action}`)}
        </Button>
      }
      title={t("confirmTitle", { action: t(`actions.${action}`), title: course.title ?? course.slug })}
      description={t(`hints.${action}`)}
      reason={{ label: t("reason"), minLength: 3 }}
      confirmLabel={t(`actions.${action}`)}
      cancelLabel={common("cancel")}
      destructive={action === "take_down"}
      onConfirm={async (note) => {
        await act.mutateAsync({ action, id: course.id, note });
      }}
      error={
        act.isError && act.variables?.id === course.id && act.variables.action === action
          ? errorText(act.error)
          : undefined
      }
      onOpenChange={(open) => {
        if (open) act.reset();
      }}
    />
  );

  const columns = col.columns([
    col.accessor((row) => row.title ?? row.slug, {
      id: "title",
      header: t("columns.title"),
      enableSorting: false,
      cell: ({ row }) => (
        <span className="grid">
          <span className="font-semibold">{row.original.title ?? row.original.slug}</span>
          <span className="text-xs text-muted-foreground">
            {row.original.professor.display_name ?? "—"} · {row.original.category ?? t("noCategory")}
          </span>
        </span>
      ),
    }),
    col.accessor("status", {
      header: t("columns.status"),
      enableSorting: false,
      cell: ({ row }) => {
        const value = courseStateOf(row.original.status);
        return (
          <span className="flex flex-wrap gap-1">
            <StatusBadge status={value} tones={COURSE_TONES} label={t(`status.${value}`)} />
            {row.original.taken_down_at ? <Badge variant="destructive">{t("takenDown")}</Badge> : null}
            {row.original.featured_position !== null ? (
              <Badge variant="secondary">{t("featured")}</Badge>
            ) : null}
          </span>
        );
      },
    }),
    col.accessor((row) => row.price?.amount_minor ?? 0, {
      id: "price",
      header: t("columns.price"),
      enableSorting: false,
      cell: ({ row }) =>
        row.original.is_free || !row.original.price ? t("free") : formatMoney(row.original.price, locale),
    }),
    col.accessor("enrollment_count", { header: t("columns.enrollments") }),
    col.accessor("published_at", {
      header: t("columns.published"),
      cell: ({ getValue }) => {
        const at = getValue();
        return at ? formatDateTime(at, locale) : "—";
      },
    }),
    col.display({
      id: "actions",
      header: () => <span className="sr-only">{t("columns.actions")}</span>,
      cell: ({ row }) => {
        const c = row.original;
        if (!canManage) return null;
        return (
          <span className="flex flex-wrap justify-end gap-1">
            {c.status === "published" && c.featured_position === null && !featuredIds.includes(c.id) ? (
              <Button
                size="sm"
                variant="ghost"
                onClick={() => setFeatured.mutate([...featuredIds, c.id])}
                disabled={setFeatured.isPending}
              >
                <Star aria-hidden />
                {t("feature")}
              </Button>
            ) : null}
            {(Object.keys(ACTIONS) as Action[]).filter((a) => c.can[a]).map((a) => actionDialog(c, a))}
          </span>
        );
      },
    }),
  ]);

  const move = (index: number, by: -1 | 1) => {
    const ids = [...featuredIds];
    const [moved] = ids.splice(index, 1);
    if (moved === undefined) return;
    ids.splice(index + by, 0, moved);
    setFeatured.mutate(ids);
  };

  return (
    <div className="mx-auto grid max-w-6xl gap-6 px-4 py-8 sm:px-6">
      <PageHeader title={t("title")} description={t("intro")} />

      {canManage ? (
        <section aria-labelledby="featured" className="grid gap-2 rounded-xl border p-4">
          <h2 id="featured" className="font-bold">
            {t("featuredTitle")}
          </h2>
          <p className="text-xs text-muted-foreground">{t("featuredHint")}</p>
          {featuredIds.length === 0 ? (
            <p className="text-sm text-muted-foreground">{t("noFeatured")}</p>
          ) : null}
          <ol className="grid gap-1 text-sm">
            {(featured.data ?? []).map((c, i) => (
              <li
                key={c.id}
                className="flex items-center justify-between gap-2 rounded-md border px-3 py-1.5"
              >
                <span>
                  {i + 1}. {c.title}
                </span>
                <span className="flex gap-1">
                  <Button
                    size="icon"
                    variant="ghost"
                    aria-label={t("moveUp", { title: c.title ?? "" })}
                    disabled={i === 0 || setFeatured.isPending}
                    onClick={() => move(i, -1)}
                  >
                    <ArrowUp aria-hidden />
                  </Button>
                  <Button
                    size="icon"
                    variant="ghost"
                    aria-label={t("moveDown", { title: c.title ?? "" })}
                    disabled={i === featuredIds.length - 1 || setFeatured.isPending}
                    onClick={() => move(i, 1)}
                  >
                    <ArrowDown aria-hidden />
                  </Button>
                  <Button
                    size="icon"
                    variant="ghost"
                    aria-label={t("unfeature", { title: c.title ?? "" })}
                    disabled={setFeatured.isPending}
                    onClick={() => setFeatured.mutate(featuredIds.filter((id) => id !== c.id))}
                  >
                    <X aria-hidden />
                  </Button>
                </span>
              </li>
            ))}
          </ol>
          {setFeatured.isError ? (
            <p role="alert" className="text-sm text-destructive">
              {errorText(setFeatured.error)}
            </p>
          ) : null}
        </section>
      ) : null}

      <div className="grid gap-4 sm:grid-cols-3">
        <form
          role="search"
          className="grid gap-2 sm:col-span-2"
          onSubmit={(e: FormEvent) => {
            e.preventDefault();
            void setSearch(draft.trim() || null);
            void setPage(1);
          }}
        >
          <Label htmlFor="course-search">{t("search")}</Label>
          <Input id="course-search" type="search" value={draft} onChange={(e) => setDraft(e.target.value)} />
        </form>
        <div className="grid gap-2">
          <Label htmlFor="course-status">{t("columns.status")}</Label>
          <select
            id="course-status"
            className={selectClass}
            value={status}
            onChange={(e) => {
              void setStatus(e.target.value);
              void setPage(1);
            }}
          >
            <option value="all">{t("allStatuses")}</option>
            {COURSE_STATES.map((value) => (
              <option key={value} value={value}>
                {t(`status.${value}`)}
              </option>
            ))}
            <option value="taken_down">{t("takenDown")}</option>
          </select>
        </div>
      </div>

      {courses.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(courses.error)}
        </p>
      ) : (
        <DataTable
          columns={columns}
          data={courses.data?.data ?? []}
          getRowId={(row) => row.id}
          caption={t("title")}
          sort={toSort(sort)}
          onSortChange={(next) => {
            void setSort(fromSort(next));
            void setPage(1);
          }}
          page={page}
          pageCount={courses.data?.meta.last_page ?? 1}
          onPageChange={(next) => void setPage(next)}
          loading={courses.isFetching}
          empty={t("empty")}
          labels={labels}
        />
      )}
    </div>
  );
}
