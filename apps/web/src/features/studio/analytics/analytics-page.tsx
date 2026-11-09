"use client";

import { success } from "@yekkola/api-client";
import {
  useProfessorAnalyticsCourse,
  useProfessorAnalyticsOverview,
} from "@yekkola/api-client/endpoints/analytics";
import { formatDate, formatMoney, type Locale } from "@yekkola/i18n";
import { EmptyState } from "@yekkola/ui/blocks/empty-state";
import { KpiCard } from "@yekkola/ui/blocks/kpi-card";
import { Button } from "@yekkola/ui/components/button";
import { Skeleton } from "@yekkola/ui/components/skeleton";
import { cn } from "@yekkola/ui/lib/utils";
import { useLocale, useTranslations } from "next-intl";
import { parseAsInteger, parseAsString, useQueryState } from "nuqs";

import { Link } from "@/i18n/navigation";

import { useErrorText } from "../hooks";

const PERIODS = [7, 30, 90, 365] as const;

type Money = { amount_minor: number; currency: string };
type Day = { date: string; enrollments: number; sales: number };

/** Revenue per currency on one line: "1 500 USD · 25 000 CDF", or a dash. */
function moneyList(list: Money[], locale: Locale): string {
  return list.length === 0 ? "—" : list.map((m) => formatMoney(m, locale)).join(" · ");
}

/**
 * My analytics (studio): enrollments, sales and what students paid, completions and active students for a chosen
 * period, compared with the period before; daily bars; each course, with where its students are now. The API
 * serves pre-aggregated daily figures (updated every hour); earnings are on the earnings page.
 */
export function AnalyticsPage() {
  const t = useTranslations("web.analytics");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const [days, setDays] = useQueryState("days", parseAsInteger.withDefault(30));
  const [selected, setSelected] = useQueryState("course", parseAsString);
  const period = (PERIODS as readonly number[]).includes(days) ? days : 30;

  const overview = useProfessorAnalyticsOverview(
    { days: period },
    { query: { select: (r) => success(r).data, placeholderData: (previous) => previous } },
  );

  // The API computes the change; null = nothing in the period before.
  const change = (pct: number | null, now: number) => {
    if (pct === null) return now === 0 ? t("noChange") : t("new");
    return t("change", { pct: pct > 0 ? `+${pct}` : String(pct) });
  };

  return (
    <div className="mx-auto grid max-w-5xl gap-8 px-4 py-8">
      <header className="flex flex-wrap items-end justify-between gap-4">
        <div className="grid gap-1">
          <h1 className="text-2xl font-extrabold">{t("title")}</h1>
          <p className="text-muted-foreground">{t("intro")}</p>
        </div>
        <div role="group" aria-label={t("period")} className="flex flex-wrap gap-1">
          {PERIODS.map((p) => (
            <Button
              key={p}
              size="sm"
              variant={p === period ? "default" : "outline"}
              aria-pressed={p === period}
              onClick={() => void setDays(p === 30 ? null : p)}
            >
              {t("days", { count: p })}
            </Button>
          ))}
        </div>
      </header>

      {overview.isError ? (
        <EmptyState title={errorText(overview.error)} />
      ) : overview.isPending ? (
        <Skeleton className="h-64 w-full" />
      ) : (
        <>
          <section aria-label={t("kpis")} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <KpiCard
              title={t("enrollments")}
              value={overview.data.totals.enrollments}
              hint={change(overview.data.changes.enrollments, overview.data.totals.enrollments)}
            />
            <KpiCard
              title={t("sales")}
              value={overview.data.totals.sales}
              hint={change(overview.data.changes.sales, overview.data.totals.sales)}
            />
            <KpiCard
              title={t("revenue")}
              value={moneyList(overview.data.totals.revenue, locale)}
              hint={t("revenueHint")}
            />
            <KpiCard
              title={t("completions")}
              value={overview.data.totals.completions}
              hint={change(overview.data.changes.completions, overview.data.totals.completions)}
            />
          </section>

          <div className="grid gap-3 md:grid-cols-2">
            <Bars title={t("enrollmentsPerDay")} days={overview.data.daily} value={(d) => d.enrollments} />
            <Bars title={t("salesPerDay")} days={overview.data.daily} value={(d) => d.sales} />
          </div>

          <p className="text-sm text-muted-foreground">
            {t("updatedHourly", {
              from: formatDate(overview.data.from, locale),
              to: formatDate(overview.data.to, locale),
            })}{" "}
            <Link href="/studio/earnings" className="font-semibold text-primary hover:underline">
              {t("toEarnings")}
            </Link>
          </p>

          <section aria-labelledby="by-course" className="grid gap-3">
            <h2 id="by-course" className="font-bold">
              {t("byCourse")}
            </h2>
            {overview.data.courses.length === 0 ? (
              <p className="text-sm text-muted-foreground">{t("noCourses")}</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <caption className="sr-only">{t("byCourse")}</caption>
                  <thead className="text-left text-muted-foreground">
                    <tr>
                      <th scope="col" className="py-1 pr-4 font-semibold">
                        {t("course")}
                      </th>
                      <th scope="col" className="py-1 pr-4 text-right font-semibold">
                        {t("enrollments")}
                      </th>
                      <th scope="col" className="py-1 pr-4 text-right font-semibold">
                        {t("sales")}
                      </th>
                      <th scope="col" className="py-1 pr-4 text-right font-semibold">
                        {t("revenue")}
                      </th>
                      <th scope="col" className="py-1 pr-4 text-right font-semibold">
                        {t("completions")}
                      </th>
                      <th scope="col" className="py-1 text-right font-semibold">
                        {t("rating")}
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {overview.data.courses.map((c) => (
                      <tr key={c.course_id} className="border-t">
                        <td className="py-1.5 pr-4">
                          <button
                            type="button"
                            aria-expanded={selected === c.course_id}
                            onClick={() => void setSelected(selected === c.course_id ? null : c.course_id)}
                            className={cn(
                              "text-left font-semibold text-primary hover:underline",
                              selected === c.course_id && "underline",
                            )}
                          >
                            {c.title ?? t("untitled")}
                          </button>
                        </td>
                        <td className="py-1.5 pr-4 text-right tabular-nums">{c.enrollments}</td>
                        <td className="py-1.5 pr-4 text-right tabular-nums">{c.sales}</td>
                        <td className="py-1.5 pr-4 text-right tabular-nums">
                          {moneyList(c.revenue, locale)}
                        </td>
                        <td className="py-1.5 pr-4 text-right tabular-nums">{c.completions}</td>
                        <td className="py-1.5 text-right tabular-nums">
                          {c.rating === null ? "—" : `${c.rating} (${c.rating_count})`}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>

          {selected && overview.data.courses.some((c) => c.course_id === selected) ? (
            <CourseDetail
              courseId={selected}
              title={overview.data.courses.find((c) => c.course_id === selected)?.title ?? t("untitled")}
              days={period}
            />
          ) : null}
        </>
      )}
    </div>
  );
}

/** One course: its daily bars and where its students are now. */
function CourseDetail({ courseId, title, days }: { courseId: string; title: string; days: number }) {
  const t = useTranslations("web.analytics");
  const errorText = useErrorText();
  const detail = useProfessorAnalyticsCourse(
    courseId,
    { days },
    { query: { select: (r) => success(r).data } },
  );

  return (
    <section aria-labelledby="course-detail" className="grid gap-4 rounded-xl border p-4">
      <h2 id="course-detail" className="font-bold">
        {title}
      </h2>
      {detail.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(detail.error)}
        </p>
      ) : detail.isPending ? (
        <Skeleton className="h-40 w-full" />
      ) : (
        <>
          <div className="grid gap-3 sm:grid-cols-3">
            <KpiCard
              title={t("activeStudents")}
              value={detail.data.totals.active_students}
              hint={t("activeHint")}
            />
            <KpiCard title={t("newReviews")} value={detail.data.totals.reviews} />
            <KpiCard
              title={t("quizPassRate")}
              value={detail.data.quiz_pass_pct === null ? "—" : `${detail.data.quiz_pass_pct} %`}
              hint={t("quizAttempts", { count: detail.data.totals.quiz_attempts })}
            />
          </div>
          <Funnel funnel={detail.data.funnel} />
          <Bars title={t("enrollmentsPerDay")} days={detail.data.daily} value={(d) => d.enrollments} />
        </>
      )}
    </section>
  );
}

function Funnel({
  funnel,
}: {
  funnel: { enrolled: number; started: number; halfway: number; completed: number };
}) {
  const t = useTranslations("web.analytics");
  const steps = [
    ["enrolled", funnel.enrolled],
    ["started", funnel.started],
    ["halfway", funnel.halfway],
    ["completed", funnel.completed],
  ] as const;
  const max = Math.max(1, funnel.enrolled);

  return (
    <figure className="grid gap-2">
      <figcaption className="text-sm font-semibold">{t("funnel")}</figcaption>
      <ol className="grid gap-1.5">
        {steps.map(([key, value]) => (
          <li key={key} className="grid grid-cols-[8rem_1fr_3rem] items-center gap-2 text-sm">
            <span>{t(`steps.${key}`)}</span>
            <span className="h-3 rounded bg-muted">
              <span className="block h-3 rounded bg-primary" style={{ width: `${(value / max) * 100}%` }} />
            </span>
            <span className="text-right tabular-nums">{value}</span>
          </li>
        ))}
      </ol>
    </figure>
  );
}

/** A small daily bar chart; the numbers are also in a table for screen readers. */
function Bars({ title, days, value }: { title: string; days: Day[]; value: (day: Day) => number }) {
  const locale = useLocale() as Locale;
  const max = Math.max(1, ...days.map(value));
  const width = 100 / Math.max(1, days.length);
  const total = days.reduce((sum, d) => sum + value(d), 0);

  return (
    <figure className="grid gap-2 rounded-xl border bg-background p-4">
      <figcaption className="flex justify-between text-sm font-semibold">
        <span>{title}</span>
        <span className="text-muted-foreground tabular-nums">{total}</span>
      </figcaption>
      <svg viewBox="0 0 100 40" preserveAspectRatio="none" className="h-28 w-full" aria-hidden>
        {days.map((d, i) => {
          const h = (value(d) / max) * 38;
          return (
            <rect
              key={d.date}
              x={i * width + width * 0.15}
              y={40 - h}
              width={width * 0.7}
              height={h}
              className="fill-primary"
            />
          );
        })}
      </svg>
      <table className="sr-only">
        <caption>{title}</caption>
        <tbody>
          {days.map((d) => (
            <tr key={d.date}>
              <th scope="row">{formatDate(d.date, locale)}</th>
              <td>{value(d)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </figure>
  );
}
