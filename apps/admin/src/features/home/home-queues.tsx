"use client";

import { success } from "@yekkola/api-client";
import { useAdminCourseVersionsIndex } from "@yekkola/api-client/endpoints/course-version";
import { useAdminProfessorApplicationsIndex } from "@yekkola/api-client/endpoints/professor-application";
import { PageHeader } from "@yekkola/ui/blocks/page-header";
import { Skeleton } from "@yekkola/ui/components/skeleton";
import { BookCheck, GraduationCap } from "lucide-react";
import { useTranslations } from "next-intl";
import type { ReactNode } from "react";

import { useSession } from "@/features/auth/session";
import { Link } from "@/i18n/navigation";

/**
 * Back-office home: the queues needing attention (architecture §5.3). Counts come from the list endpoints'
 * totals; the full dashboard (KPIs, charts) comes later (TODO 2.2).
 */
export function HomeQueues() {
  const t = useTranslations("admin.home");
  const session = useSession();
  const canReview = session.can("moderation.review");

  const applications = useAdminProfessorApplicationsIndex(
    { per_page: 1, "filter[status]": "submitted" },
    { query: { enabled: canReview, select: (r) => success(r).meta.total } },
  );
  const versions = useAdminCourseVersionsIndex(
    { per_page: 1, "filter[state]": "in_review" },
    { query: { enabled: canReview, select: (r) => success(r).meta.total } },
  );

  return (
    <div className="mx-auto grid max-w-6xl gap-6 px-4 py-8 sm:px-6">
      <PageHeader title={t("title")} description={t("subtitle")} />
      {canReview ? (
        <section aria-labelledby="queues-title" className="grid gap-3">
          <h2 id="queues-title" className="text-lg font-bold">
            {t("queuesTitle")}
          </h2>
          <ul className="grid gap-3 sm:grid-cols-2">
            <QueueCard
              href="/applications?status=submitted"
              icon={<GraduationCap aria-hidden className="size-5" />}
              label={t("pendingApplications")}
              count={applications.data}
              loading={applications.isPending}
            />
            <QueueCard
              href="/moderation/courses?state=in_review"
              icon={<BookCheck aria-hidden className="size-5" />}
              label={t("versionsInReview")}
              count={versions.data}
              loading={versions.isPending}
            />
          </ul>
        </section>
      ) : (
        <p className="text-sm text-muted-foreground">{t("nothingForYou")}</p>
      )}
    </div>
  );
}

function QueueCard({
  href,
  icon,
  label,
  count,
  loading,
}: {
  href: string;
  icon: ReactNode;
  label: string;
  count: number | undefined;
  loading: boolean;
}) {
  return (
    <li>
      <Link
        href={href}
        className="flex items-center justify-between gap-4 rounded-xl border bg-background p-4 hover:border-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
      >
        <span className="flex items-center gap-3 font-semibold">
          <span className="text-primary">{icon}</span>
          {label}
        </span>
        {loading ? (
          <Skeleton className="h-8 w-10" />
        ) : (
          <span className="text-3xl font-extrabold tabular-nums">{count ?? "—"}</span>
        )}
      </Link>
    </li>
  );
}
