"use client";

import { success } from "@yekkola/api-client";
import { useMeEnrollmentsIndex } from "@yekkola/api-client/endpoints/learning";
import { formatDate, type Locale } from "@yekkola/i18n";
import { EmptyState } from "@yekkola/ui/blocks/empty-state";
import { PageHeader } from "@yekkola/ui/blocks/page-header";
import { Button } from "@yekkola/ui/components/button";
import { Skeleton } from "@yekkola/ui/components/skeleton";
import { cn } from "@yekkola/ui/lib/utils";
import { BookOpen, GraduationCap } from "lucide-react";
import Image from "next/image";
import { useLocale, useTranslations } from "next-intl";
import { parseAsInteger, parseAsStringLiteral, useQueryState } from "nuqs";

import { useSession } from "@/features/auth/hooks/use-session";
import { Link } from "@/i18n/navigation";

const STATES = ["all", "in_progress", "completed"] as const;

/** My courses (PRD-06 FR-01): progress, last activity, continue, and in-progress / completed filters. */
export function MyCourses() {
  const t = useTranslations("web.myCourses");
  const errors = useTranslations("errors");
  const common = useTranslations("common");
  const pagination = useTranslations("web.pagination");
  const nav = useTranslations("web.nav");
  const locale = useLocale() as Locale;
  const { user } = useSession();
  const [state, setState] = useQueryState("state", parseAsStringLiteral(STATES).withDefault("all"));
  const [page, setPage] = useQueryState("page", parseAsInteger.withDefault(1));
  const result = useMeEnrollmentsIndex(
    { page, per_page: 24, ...(state === "all" ? {} : { "filter[state]": state }) },
    { query: { select: (r) => success(r) } },
  );
  const enrollments = { ...result, data: result.data?.data };
  const lastPage = result.data?.meta.last_page ?? 1;

  return (
    <div className="mx-auto grid max-w-6xl gap-6 px-4 py-8 sm:py-12">
      <PageHeader
        title={t("title")}
        actions={
          <>
            {/* The header's Studio button is hidden on phones; professors find it here. */}
            {user?.roles.includes("professor") ? (
              <Button asChild variant="outline" size="sm" className="sm:hidden">
                <Link href="/studio/courses">{nav("studio")}</Link>
              </Button>
            ) : null}
            <Button asChild variant="outline" size="sm">
              <Link href="/orders">{nav("orders")}</Link>
            </Button>
          </>
        }
      />
      <div role="tablist" aria-label={t("title")} className="flex gap-2">
        {STATES.map((value) => (
          <button
            key={value}
            role="tab"
            type="button"
            aria-selected={state === value}
            onClick={() => {
              void setPage(null);
              void setState(value);
            }}
            className={cn(
              "rounded-full border px-4 py-1.5 text-sm font-semibold transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none",
              state === value ? "border-primary bg-primary text-primary-foreground" : "hover:bg-accent",
            )}
          >
            {t(value === "all" ? "all" : value === "in_progress" ? "inProgress" : "completed")}
          </button>
        ))}
      </div>

      {enrollments.isPending ? (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} className="h-64 w-full" />
          ))}
        </div>
      ) : enrollments.isError ? (
        <EmptyState
          title={errors("generic")}
          action={
            <Button variant="outline" onClick={() => result.refetch()}>
              {common("retry")}
            </Button>
          }
        />
      ) : !enrollments.data || enrollments.data.length === 0 ? (
        <EmptyState
          icon={<GraduationCap />}
          title={state === "completed" ? t("emptyCompleted") : t("empty")}
          action={
            <Button asChild>
              <Link href="/courses">{t("browse")}</Link>
            </Button>
          }
        />
      ) : (
        <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {enrollments.data.map((enrollment) => (
            <li
              key={enrollment.enrollment_id}
              className="flex flex-col overflow-hidden rounded-xl border bg-card shadow-sm"
            >
              <div className="relative aspect-video bg-indigo-100 dark:bg-indigo-950">
                {enrollment.course.cover_url ? (
                  <Image
                    src={enrollment.course.cover_url}
                    alt=""
                    fill
                    sizes="(min-width: 1024px) 33vw, (min-width: 640px) 50vw, 100vw"
                    className="object-cover"
                  />
                ) : (
                  <BookOpen aria-hidden className="absolute inset-0 m-auto size-10 text-indigo-400" />
                )}
              </div>
              <div className="flex flex-1 flex-col gap-3 p-4">
                <h2 className="line-clamp-2 font-bold">{enrollment.course.title}</h2>
                <p className="text-sm text-muted-foreground">{enrollment.course.professor.display_name}</p>
                <div className="grid gap-1">
                  <div
                    className="h-2 overflow-hidden rounded-full bg-muted"
                    role="progressbar"
                    aria-valuemin={0}
                    aria-valuemax={100}
                    aria-valuenow={enrollment.progress_pct}
                    aria-label={t("progress", { pct: enrollment.progress_pct })}
                  >
                    <div
                      className="h-full rounded-full bg-primary"
                      style={{ width: `${enrollment.progress_pct}%` }}
                    />
                  </div>
                  <p className="text-xs text-muted-foreground">
                    {t("progress", { pct: enrollment.progress_pct })}
                    {enrollment.completed_at
                      ? ` · ${t("completedOn", { date: formatDate(enrollment.completed_at, locale) })}`
                      : enrollment.last_activity_at
                        ? ` · ${t("lastActivity", { date: formatDate(enrollment.last_activity_at, locale) })}`
                        : ""}
                  </p>
                </div>
                <Button asChild className="mt-auto">
                  <Link href={`/learn/${enrollment.course.id}`}>
                    {enrollment.progress_pct > 0 ? t("continue") : t("start")}
                  </Link>
                </Button>
              </div>
            </li>
          ))}
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
