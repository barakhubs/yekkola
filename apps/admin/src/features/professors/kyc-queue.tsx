"use client";

import { success } from "@yekkola/api-client";
import { useAdminKycSubmissionsIndex } from "@yekkola/api-client/endpoints/kyc-submission";
import { EmptyState } from "@yekkola/ui/blocks/empty-state";
import { PageHeader } from "@yekkola/ui/blocks/page-header";
import { Button } from "@yekkola/ui/components/button";
import { Label } from "@yekkola/ui/components/label";
import { Skeleton } from "@yekkola/ui/components/skeleton";
import { useTranslations } from "next-intl";
import { parseAsInteger, parseAsString, useQueryState } from "nuqs";

import { useErrorText } from "@/features/common/errors";
import { selectClass } from "@/features/common/styles";

import { KycSubmissionCard } from "./kyc-submission-card";
import { KYC_STATES } from "./status";

/** Identity checks (PRD-02 FR-08, PRD-09 FR-06): pending ones oldest first, so nobody waits longest. */
export function KycQueue() {
  const t = useTranslations("admin.kyc");
  const errorText = useErrorText();
  const [status, setStatus] = useQueryState("status", parseAsString.withDefault("pending"));
  const [page, setPage] = useQueryState("page", parseAsInteger.withDefault(1));

  const submissions = useAdminKycSubmissionsIndex(
    {
      page,
      per_page: 20,
      ...(status === "all" ? {} : { "filter[status]": status }),
      sort: status === "pending" ? "created_at" : "-created_at",
    },
    { query: { select: (r) => success(r), placeholderData: (previous) => previous } },
  );
  const lastPage = submissions.data?.meta.last_page ?? 1;

  return (
    <div className="mx-auto grid max-w-4xl gap-6 px-4 py-8 sm:px-6">
      <PageHeader title={t("title")} description={t("intro")} />

      <div className="grid max-w-xs gap-2">
        <Label htmlFor="kyc-status">{t("statusFilter")}</Label>
        <select
          id="kyc-status"
          className={selectClass}
          value={status}
          onChange={(e) => {
            void setStatus(e.target.value);
            void setPage(1);
          }}
        >
          {KYC_STATES.filter((s) => s !== "none").map((value) => (
            <option key={value} value={value}>
              {t(`status.${value}`)}
            </option>
          ))}
          <option value="all">{t("all")}</option>
        </select>
      </div>

      {submissions.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(submissions.error)}
        </p>
      ) : submissions.isPending ? (
        <Skeleton className="h-40 w-full" />
      ) : submissions.data.data.length === 0 ? (
        <EmptyState title={status === "pending" ? t("nothingPending") : t("empty")} />
      ) : (
        <div className="grid gap-3" aria-busy={submissions.isFetching}>
          {submissions.data.data.map((s) => (
            <KycSubmissionCard key={s.id} submission={s} showProfessor />
          ))}
        </div>
      )}

      {lastPage > 1 ? (
        <nav className="flex items-center justify-between gap-2" aria-label={t("pages")}>
          <Button variant="outline" disabled={page <= 1} onClick={() => void setPage(page - 1)}>
            {t("previous")}
          </Button>
          <span className="text-sm text-muted-foreground">{t("pageOf", { page, total: lastPage })}</span>
          <Button variant="outline" disabled={page >= lastPage} onClick={() => void setPage(page + 1)}>
            {t("next")}
          </Button>
        </nav>
      ) : null}
    </div>
  );
}
