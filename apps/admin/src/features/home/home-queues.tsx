"use client";

import { success } from "@yekkola/api-client";
import { useAdminDashboardShow } from "@yekkola/api-client/endpoints/dashboard";
import type { AdminDashboardShow200Data } from "@yekkola/api-client/model";
import { formatDate, formatMoney, type Locale } from "@yekkola/i18n";
import { KpiCard } from "@yekkola/ui/blocks/kpi-card";
import { PageHeader } from "@yekkola/ui/blocks/page-header";
import { Skeleton } from "@yekkola/ui/components/skeleton";
import { BookCheck, CreditCard, GraduationCap, IdCard, ReceiptText, Wallet } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import type { ReactNode } from "react";

import { useErrorText } from "@/features/common/errors";
import { Link } from "@/i18n/navigation";

type Dashboard = AdminDashboardShow200Data;

/**
 * Back-office home (PRD-09 FR-01): the last 30 days against the 30 before, active students, daily charts, and the
 * queues needing attention. The API leaves out what this staff member may not see (money, queues they don't work).
 */
export function HomeQueues() {
  const t = useTranslations("admin.home");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const dashboard = useAdminDashboardShow({ query: { select: (r) => success(r).data } });

  if (dashboard.isError) {
    return (
      <div className="mx-auto max-w-6xl px-4 py-8 sm:px-6">
        <p role="alert" className="text-sm text-destructive">
          {errorText(dashboard.error)}
        </p>
      </div>
    );
  }

  const d = dashboard.data;
  const change = (value: number, previous: number) =>
    previous === 0
      ? t("noPrevious")
      : t("change", { pct: Math.round(((value - previous) / previous) * 100) });

  return (
    <div className="mx-auto grid max-w-6xl gap-8 px-4 py-8 sm:px-6">
      <PageHeader
        title={t("title")}
        description={
          d ? t("period", { from: formatDate(d.from, locale), to: formatDate(d.to, locale) }) : t("subtitle")
        }
      />

      {d ? <Queues queues={d.queues} /> : <Skeleton className="h-24 w-full" />}

      <section aria-labelledby="kpis" className="grid gap-3">
        <h2 id="kpis" className="text-lg font-bold">
          {t("kpisTitle")}
        </h2>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {d?.money
            ? Object.entries(d.money.gmv).map(([currency, pair]) => (
                <KpiCard
                  key={`gmv-${currency}`}
                  title={t("gmv", { currency })}
                  value={formatMoney({ amount_minor: pair.value, currency }, locale)}
                  hint={change(pair.value, pair.previous)}
                />
              ))
            : null}
          {d?.money
            ? Object.entries(d.money.net_revenue).map(([currency, pair]) => (
                <KpiCard
                  key={`net-${currency}`}
                  title={t("netRevenue", { currency })}
                  value={formatMoney({ amount_minor: pair.value, currency }, locale)}
                  hint={change(pair.value, pair.previous)}
                />
              ))
            : null}
          <KpiCard
            title={t("signups")}
            value={d?.kpis.signups.value ?? "—"}
            hint={d ? change(d.kpis.signups.value, d.kpis.signups.previous) : undefined}
            loading={!d}
          />
          <KpiCard
            title={t("enrollments")}
            value={d?.kpis.enrollments.value ?? "—"}
            hint={d ? change(d.kpis.enrollments.value, d.kpis.enrollments.previous) : undefined}
            loading={!d}
          />
          <KpiCard
            title={t("ordersPaid")}
            value={d?.kpis.orders_paid.value ?? "—"}
            hint={d ? change(d.kpis.orders_paid.value, d.kpis.orders_paid.previous) : undefined}
            loading={!d}
          />
          <KpiCard
            title={t("conversion")}
            value={d?.kpis.paid_conversion_pct.value == null ? "—" : `${d.kpis.paid_conversion_pct.value} %`}
            hint={t("conversionHint")}
            loading={!d}
          />
          <KpiCard
            title={t("activeStudents")}
            value={d ? `${d.kpis.active_students_7d} / ${d.kpis.active_students_30d}` : "—"}
            hint={t("activeStudentsHint")}
            loading={!d}
          />
          <KpiCard
            title={t("coursesPublished")}
            value={d?.kpis.courses_published.value ?? "—"}
            hint={d ? change(d.kpis.courses_published.value, d.kpis.courses_published.previous) : undefined}
            loading={!d}
          />
        </div>
      </section>

      {d ? (
        <section aria-labelledby="trends" className="grid gap-4 lg:grid-cols-2">
          <h2 id="trends" className="text-lg font-bold lg:col-span-2">
            {t("trendsTitle")}
          </h2>
          <BarSeries
            title={t("enrollments")}
            series={d.series}
            value={(r) => r.enrollments}
            locale={locale}
          />
          <BarSeries title={t("ordersPaid")} series={d.series} value={(r) => r.orders_paid} locale={locale} />
        </section>
      ) : null}
    </div>
  );
}

function Queues({ queues }: { queues: Dashboard["queues"] }) {
  const t = useTranslations("admin.home");
  const cards: {
    key: string;
    href: string | null;
    icon: ReactNode;
    label: string;
    count: number;
    overdue?: number;
  }[] = [];
  if (queues.applications)
    cards.push({
      key: "apps",
      href: "/applications?status=pending",
      icon: <GraduationCap />,
      label: t("pendingApplications"),
      count: queues.applications.count,
      overdue: queues.applications.overdue,
    });
  if (queues.course_reviews)
    cards.push({
      key: "reviews",
      href: "/moderation/courses?state=in_review",
      icon: <BookCheck />,
      label: t("versionsInReview"),
      count: queues.course_reviews.count,
      overdue: queues.course_reviews.overdue,
    });
  if (queues.kyc !== null)
    cards.push({ key: "kyc", href: null, icon: <IdCard />, label: t("kyc"), count: queues.kyc });
  if (queues.refunds !== null)
    cards.push({
      key: "refunds",
      href: "/finance/refunds",
      icon: <ReceiptText />,
      label: t("refunds"),
      count: queues.refunds,
    });
  if (queues.stuck_payments !== null)
    cards.push({
      key: "stuck",
      href: "/orders?only=stuck",
      icon: <CreditCard />,
      label: t("stuckPayments"),
      count: queues.stuck_payments,
    });
  if (queues.failed_payouts !== null)
    cards.push({
      key: "payouts",
      href: "/finance/payouts",
      icon: <Wallet />,
      label: t("failedPayouts"),
      count: queues.failed_payouts,
    });

  return (
    <section aria-labelledby="queues-title" className="grid gap-3">
      <h2 id="queues-title" className="text-lg font-bold">
        {t("queuesTitle")}
      </h2>
      {cards.length === 0 ? (
        <p className="text-sm text-muted-foreground">{t("nothingForYou")}</p>
      ) : (
        <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {cards.map((c) => (
            <li key={c.key}>
              {c.href ? (
                <Link
                  href={c.href}
                  className="flex items-center justify-between gap-4 rounded-xl border bg-background p-4 hover:border-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                  <span className="grid gap-1">
                    <span className="flex items-center gap-3 font-semibold [&_svg]:size-5 [&_svg]:text-primary">
                      {c.icon}
                      {c.label}
                    </span>
                    {c.overdue ? (
                      <span className="text-xs font-semibold text-destructive">
                        {t("overdue", { count: c.overdue })}
                      </span>
                    ) : null}
                  </span>
                  <span className="text-3xl font-extrabold tabular-nums">{c.count}</span>
                </Link>
              ) : (
                // No screen for this queue yet (KYC viewer, TODO 2.2).
                <div className="flex items-center justify-between gap-4 rounded-xl border bg-background p-4">
                  <span className="grid gap-1">
                    <span className="flex items-center gap-3 font-semibold [&_svg]:size-5 [&_svg]:text-primary">
                      {c.icon}
                      {c.label}
                    </span>
                    {c.overdue ? (
                      <span className="text-xs font-semibold text-destructive">
                        {t("overdue", { count: c.overdue })}
                      </span>
                    ) : null}
                  </span>
                  <span className="text-3xl font-extrabold tabular-nums">{c.count}</span>
                </div>
              )}
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

/** A small daily bar chart; the numbers are also in a table for screen readers. */
function BarSeries({
  title,
  series,
  value,
  locale,
}: {
  title: string;
  series: Dashboard["series"];
  value: (row: Dashboard["series"][number]) => number;
  locale: Locale;
}) {
  const max = Math.max(1, ...series.map(value));
  const width = 100 / Math.max(1, series.length);

  return (
    <figure className="grid gap-2 rounded-xl border bg-background p-4">
      <figcaption className="text-sm font-semibold">{title}</figcaption>
      <svg viewBox="0 0 100 40" preserveAspectRatio="none" className="h-32 w-full" aria-hidden>
        {series.map((row, i) => {
          const h = (value(row) / max) * 38;
          return (
            <rect
              key={row.date}
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
        <tbody>
          {series.map((row) => (
            <tr key={row.date}>
              <th scope="row">{formatDate(row.date, locale)}</th>
              <td>{value(row)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </figure>
  );
}
