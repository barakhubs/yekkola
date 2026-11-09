"use client";

import { apiUrl, success } from "@yekkola/api-client";
import { useMeCertificatesIndex } from "@yekkola/api-client/endpoints/certificate";
import { formatDate, type Locale } from "@yekkola/i18n";
import { EmptyState } from "@yekkola/ui/blocks/empty-state";
import { PageHeader } from "@yekkola/ui/blocks/page-header";
import { Button } from "@yekkola/ui/components/button";
import { Skeleton } from "@yekkola/ui/components/skeleton";
import { Award, Download } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useState } from "react";

import { Link } from "@/i18n/navigation";

/** My certificates (PRD-06 FR-10): newest first, each with its public verification page and PDF. */
export function MyCertificates() {
  const t = useTranslations("web.certificates");
  const errors = useTranslations("errors");
  const locale = useLocale() as Locale;
  const [page, setPage] = useState(1);
  const certificates = useMeCertificatesIndex(
    { page, per_page: 20 },
    { query: { select: (r) => success(r), placeholderData: (previous) => previous } },
  );
  const lastPage = certificates.data?.meta.last_page ?? 1;

  return (
    <div className="mx-auto grid max-w-4xl gap-6 px-4 py-8 sm:py-12">
      <PageHeader title={t("title")} description={t("intro")} />
      {certificates.isError ? (
        <EmptyState title={errors("generic")} />
      ) : certificates.isPending ? (
        <Skeleton className="h-40 w-full" />
      ) : certificates.data.data.length === 0 ? (
        <EmptyState
          title={t("empty")}
          action={
            <Button asChild>
              <Link href="/my-courses">{t("toMyCourses")}</Link>
            </Button>
          }
        />
      ) : (
        <ul className="grid gap-3">
          {certificates.data.data.map((c) => (
            <li
              key={c.id}
              className="flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4"
            >
              <span className="flex items-start gap-3">
                <Award aria-hidden className="mt-0.5 size-6 text-amber-700 dark:text-amber-300" />
                <span className="grid gap-0.5">
                  <span className="font-semibold">{c.course.title}</span>
                  <span className="text-sm text-muted-foreground">
                    {t("issued", { date: formatDate(c.issued_at, locale), professor: c.professor_name })}
                  </span>
                  {c.revoked_at ? (
                    <span className="text-sm font-semibold text-destructive">
                      {t("revoked", { date: formatDate(c.revoked_at, locale) })}
                    </span>
                  ) : null}
                </span>
              </span>
              {c.revoked_at ? null : (
                <span className="flex flex-wrap gap-2">
                  <Button asChild variant="outline" size="sm">
                    <Link href={`/certificates/${c.serial}`}>{t("view")}</Link>
                  </Button>
                  <Button asChild size="sm">
                    <a href={apiUrl(`/api/v1/me/certificates/${c.id}/pdf`)}>
                      <Download aria-hidden className="size-4" />
                      {t("pdf")}
                    </a>
                  </Button>
                </span>
              )}
            </li>
          ))}
        </ul>
      )}
      {lastPage > 1 ? (
        <nav className="flex items-center justify-between gap-2" aria-label={t("title")}>
          <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>
            {t("previous")}
          </Button>
          <span className="text-sm text-muted-foreground">{t("pageOf", { page, total: lastPage })}</span>
          <Button variant="outline" size="sm" disabled={page >= lastPage} onClick={() => setPage(page + 1)}>
            {t("next")}
          </Button>
        </nav>
      ) : null}
    </div>
  );
}
