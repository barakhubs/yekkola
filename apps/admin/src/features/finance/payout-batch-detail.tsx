"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { success } from "@yekkola/api-client";
import {
  adminPayoutBatchesApprove,
  adminPayoutsExclude,
  getAdminPayoutBatchesIndexQueryKey,
  getAdminPayoutBatchesShowQueryKey,
  useAdminPayoutBatchesShow,
} from "@yekkola/api-client/endpoints/payout-batch";
import type { PayoutResource } from "@yekkola/api-client/model";
import { formatDateTime, formatMoney, type Locale } from "@yekkola/i18n";
import { ConfirmDialog } from "@yekkola/ui/blocks/confirm-dialog";
import { EmptyState } from "@yekkola/ui/blocks/empty-state";
import { StatusBadge } from "@yekkola/ui/blocks/status-badge";
import { Button } from "@yekkola/ui/components/button";
import { Skeleton } from "@yekkola/ui/components/skeleton";
import { ArrowLeft } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useState } from "react";

import { useErrorText } from "@/features/common/errors";
import { Link } from "@/i18n/navigation";

import { BATCH_TONES, batchStateOf, PAYOUT_TONES, payoutStateOf } from "./status";

/**
 * One payout batch (PRD-08 FR-07): totals and every line with the professor, number (masked), amount and result.
 * While it is a draft, finance leaves lines out (with a reason) and approves; the lines are then paid one by one
 * and the page follows them until the batch is done.
 */
export function PayoutBatchDetail({ id }: { id: string }) {
  const t = useTranslations("admin.payouts");
  const common = useTranslations("common");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const queryClient = useQueryClient();
  // One key for this approval: a retry after a lost answer is the same request (`Idempotency-Key`).
  const [approvalKey] = useState(() => crypto.randomUUID());
  const batch = useAdminPayoutBatchesShow(id, {
    query: {
      select: (r) => success(r).data,
      refetchInterval: (query) =>
        query.state.data && success(query.state.data).data.status === "processing" ? 5000 : false,
    },
  });
  const refresh = () =>
    Promise.all([
      queryClient.invalidateQueries({ queryKey: getAdminPayoutBatchesShowQueryKey(id) }),
      queryClient.invalidateQueries({ queryKey: getAdminPayoutBatchesIndexQueryKey() }),
    ]);

  const approve = useMutation({
    mutationFn: () => adminPayoutBatchesApprove(id, { headers: { "Idempotency-Key": approvalKey } }),
    onSettled: refresh,
  });
  const exclude = useMutation({
    mutationFn: ({ payout, note }: { payout: string; note: string }) => adminPayoutsExclude(payout, { note }),
    onSettled: refresh,
  });

  if (batch.isPending) {
    return (
      <div className="mx-auto grid max-w-6xl gap-4 px-4 py-8 sm:px-6">
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }
  if (batch.isError) {
    return (
      <div className="mx-auto max-w-xl px-4 py-16">
        <EmptyState title={errorText(batch.error)} />
      </div>
    );
  }

  const b = batch.data;
  const state = batchStateOf(b.status);
  const lines: PayoutResource[] = b.payouts ?? [];

  return (
    <div className="mx-auto grid max-w-6xl gap-6 px-4 py-8 sm:px-6">
      <Link
        href="/finance/payouts"
        className="flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
      >
        <ArrowLeft aria-hidden className="size-4" />
        {t("back")}
      </Link>

      <header className="flex flex-wrap items-start justify-between gap-4">
        <div className="grid gap-2">
          <h1 className="text-2xl font-extrabold">
            {t("detailTitle", { currency: b.currency, date: formatDateTime(b.created_at, locale) })}
          </h1>
          <span className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
            <StatusBadge status={state} tones={BATCH_TONES} label={t(`status.${state}`)} />
            {b.built_by_schedule ? t("bySchedule") : t("byHand")}
          </span>
        </div>
        <dl className="grid gap-1 text-right">
          <dt className="text-xs font-semibold text-muted-foreground">{t("columns.total")}</dt>
          <dd className="text-2xl font-extrabold tabular-nums">{formatMoney(b.total, locale)}</dd>
          <dd className="text-sm text-muted-foreground tabular-nums">
            {t("paidSoFar", { amount: formatMoney(b.paid_total, locale) })}
          </dd>
        </dl>
      </header>

      {b.can.approve ? (
        <div className="flex flex-wrap items-center gap-3">
          <ConfirmDialog
            trigger={<Button>{t("approve")}</Button>}
            title={t("approveConfirm", { amount: formatMoney(b.total, locale) })}
            description={t("approveHint")}
            confirmLabel={t("approve")}
            cancelLabel={common("cancel")}
            onConfirm={async () => {
              await approve.mutateAsync();
            }}
            error={approve.isError ? errorText(approve.error) : undefined}
            onOpenChange={(open) => {
              if (open) approve.reset();
            }}
          />
          <p className="text-sm text-muted-foreground">{t("draftHint")}</p>
        </div>
      ) : null}

      <div className="overflow-x-auto rounded-xl border">
        <table className="w-full text-sm">
          <caption className="sr-only">{t("linesCaption")}</caption>
          <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
            <tr>
              <th scope="col" className="px-3 py-2">
                {t("columns.professor")}
              </th>
              <th scope="col" className="px-3 py-2">
                {t("columns.number")}
              </th>
              <th scope="col" className="px-3 py-2 text-right">
                {t("columns.amount")}
              </th>
              <th scope="col" className="px-3 py-2">
                {t("columns.status")}
              </th>
              <th scope="col" className="px-3 py-2">
                <span className="sr-only">{t("columns.actions")}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            {lines.map((line) => {
              const lineState = payoutStateOf(line.status);
              const detail = line.excluded_reason ?? line.failure_code;
              return (
                <tr key={line.id} className="border-t align-top">
                  <td className="px-3 py-2 font-semibold">{line.professor.display_name ?? line.name}</td>
                  <td className="px-3 py-2 whitespace-nowrap">
                    {t(`rails.${line.rail}` as never)} · {line.msisdn}
                  </td>
                  <td className="px-3 py-2 text-right tabular-nums">{formatMoney(line.amount, locale)}</td>
                  <td className="px-3 py-2">
                    <StatusBadge
                      status={lineState}
                      tones={PAYOUT_TONES}
                      label={t(`lineStatus.${lineState}`)}
                    />
                    {detail ? <p className="mt-1 text-xs text-muted-foreground">{detail}</p> : null}
                  </td>
                  <td className="px-3 py-2 text-right">
                    {b.can.exclude && lineState === "pending" ? (
                      <ConfirmDialog
                        trigger={
                          <Button size="sm" variant="ghost">
                            {t("exclude")}
                          </Button>
                        }
                        title={t("excludeConfirm", { name: line.professor.display_name ?? line.name })}
                        description={t("excludeHint")}
                        reason={{ label: t("excludeReason"), minLength: 3 }}
                        confirmLabel={t("exclude")}
                        cancelLabel={common("cancel")}
                        destructive
                        onConfirm={async (note) => {
                          await exclude.mutateAsync({ payout: line.id, note });
                        }}
                        error={
                          exclude.isError && exclude.variables?.payout === line.id
                            ? errorText(exclude.error)
                            : undefined
                        }
                        onOpenChange={(open) => {
                          if (open) exclude.reset();
                        }}
                      />
                    ) : null}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}
