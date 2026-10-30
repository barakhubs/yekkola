"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { success } from "@yekkola/api-client";
import {
  adminRefundsApprove,
  adminRefundsReject,
  adminRefundsRetry,
  getAdminRefundsIndexQueryKey,
  getAdminRefundsShowQueryKey,
  useAdminRefundsShow,
} from "@yekkola/api-client/endpoints/refund";
import { formatDateTime, formatMoney, type Locale } from "@yekkola/i18n";
import { ConfirmDialog } from "@yekkola/ui/blocks/confirm-dialog";
import { EmptyState } from "@yekkola/ui/blocks/empty-state";
import { StatusBadge } from "@yekkola/ui/blocks/status-badge";
import { Badge } from "@yekkola/ui/components/badge";
import { Button } from "@yekkola/ui/components/button";
import { Skeleton } from "@yekkola/ui/components/skeleton";
import { ArrowLeft } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import type { ReactNode } from "react";

import { useErrorText } from "@/features/common/errors";
import { Link } from "@/i18n/navigation";

import { REFUND_TONES, refundStateOf } from "./status";

type Decision = "approve" | "reject" | "retry";

/**
 * One refund (PRD-09 FR-12): the request, the facts the policy looks at (days since payment, progress) next to
 * its limits, and the decisions the API allows: approve (note optional), reject or retry a failed payout (note
 * required). Approving revokes access and sends the money back to the number that paid.
 */
export function RefundDetail({ id }: { id: string }) {
  const t = useTranslations("admin.refunds");
  const common = useTranslations("common");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const queryClient = useQueryClient();
  const refund = useAdminRefundsShow(id, { query: { select: (r) => success(r).data } });

  const decide = useMutation({
    mutationFn: async ({ decision, note }: { decision: Decision; note: string }) => {
      const body = { note }; // empty on approve: the note is optional there
      if (decision === "approve") return adminRefundsApprove(id, body);
      if (decision === "reject") return adminRefundsReject(id, body);
      return adminRefundsRetry(id, body);
    },
    // Refused too (decided by someone else meanwhile): read it again so state and actions are current.
    onSettled: () =>
      Promise.all([
        queryClient.invalidateQueries({ queryKey: getAdminRefundsShowQueryKey(id) }),
        queryClient.invalidateQueries({ queryKey: getAdminRefundsIndexQueryKey() }),
      ]),
  });

  if (refund.isPending) {
    return (
      <div className="mx-auto grid max-w-4xl gap-4 px-4 py-8 sm:px-6">
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }
  if (refund.isError) {
    return (
      <div className="mx-auto max-w-xl px-4 py-16">
        <EmptyState title={errorText(refund.error)} />
      </div>
    );
  }

  const r = refund.data;
  const state = refundStateOf(r.status);
  const policy = r.policy;
  const dialogError = (decision: Decision) =>
    decide.isError && decide.variables?.decision === decision ? errorText(decide.error) : undefined;
  const resetOnOpen = (open: boolean) => {
    if (open) decide.reset();
  };

  return (
    <div className="mx-auto grid max-w-4xl gap-6 px-4 py-8 sm:px-6">
      <Link
        href="/finance/refunds"
        className="flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
      >
        <ArrowLeft aria-hidden className="size-4" />
        {t("back")}
      </Link>

      <header className="flex flex-wrap items-start justify-between gap-4">
        <div className="grid gap-2">
          <h1 className="text-2xl font-extrabold">{t("detailTitle", { order: r.order_number })}</h1>
          <span className="flex flex-wrap gap-2">
            <StatusBadge status={state} tones={REFUND_TONES} label={t(`status.${state}`)} />
            {r.flag ? <Badge variant="destructive">{t(`flags.${r.flag}` as never)}</Badge> : null}
          </span>
        </div>
        <span className="text-2xl font-extrabold tabular-nums">{formatMoney(r.amount, locale)}</span>
      </header>

      <dl className="grid gap-3 rounded-xl border p-4 sm:grid-cols-2">
        <Fact label={t("course")}>{r.course ?? t("unapplied")}</Fact>
        <Fact label={t("columns.requested")}>{formatDateTime(r.created_at, locale)}</Fact>
        <Fact label={t("reason")}>{r.reason ?? "—"}</Fact>
        {policy ? (
          <>
            <Fact label={t("daysSincePayment")}>
              {t("ofLimit", { value: policy.days_since_payment ?? "—", limit: policy.refund_window_days })}
            </Fact>
            <Fact label={t("progress")}>
              {t("ofLimitPct", { value: policy.progress_pct ?? "—", limit: policy.max_progress_pct })}
            </Fact>
          </>
        ) : null}
        {r.decision_note ? <Fact label={t("decisionNote")}>{r.decision_note}</Fact> : null}
        {r.failure_code ? <Fact label={t("failure")}>{r.failure_code}</Fact> : null}
        {r.completed_at ? <Fact label={t("completed")}>{formatDateTime(r.completed_at, locale)}</Fact> : null}
      </dl>

      <div className="flex flex-wrap gap-2">
        {r.can.approve ? (
          <ConfirmDialog
            trigger={<Button>{t("approve")}</Button>}
            title={t("approveConfirm", { amount: formatMoney(r.amount, locale) })}
            description={t("approveHint")}
            confirmLabel={t("approve")}
            cancelLabel={common("cancel")}
            onConfirm={async () => {
              await decide.mutateAsync({ decision: "approve", note: "" });
            }}
            error={dialogError("approve")}
            onOpenChange={resetOnOpen}
          />
        ) : null}
        {r.can.reject ? (
          <ConfirmDialog
            trigger={<Button variant="destructive">{t("reject")}</Button>}
            title={t("rejectConfirm")}
            description={t("rejectHint")}
            reason={{ label: t("noteToStudent"), minLength: 3 }}
            confirmLabel={t("reject")}
            cancelLabel={common("cancel")}
            destructive
            onConfirm={async (note) => {
              await decide.mutateAsync({ decision: "reject", note });
            }}
            error={dialogError("reject")}
            onOpenChange={resetOnOpen}
          />
        ) : null}
        {r.can.retry ? (
          <ConfirmDialog
            trigger={<Button variant="outline">{t("retry")}</Button>}
            title={t("retryConfirm")}
            description={t("retryHint")}
            reason={{ label: t("internalNote"), minLength: 3 }}
            confirmLabel={t("retry")}
            cancelLabel={common("cancel")}
            onConfirm={async (note) => {
              await decide.mutateAsync({ decision: "retry", note });
            }}
            error={dialogError("retry")}
            onOpenChange={resetOnOpen}
          />
        ) : null}
      </div>
    </div>
  );
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="grid gap-0.5">
      <dt className="text-xs font-semibold text-muted-foreground">{label}</dt>
      <dd className="text-sm">{children}</dd>
    </div>
  );
}
