"use client";

import { apiUrl, success } from "@yekkola/api-client";
import {
  useProfessorEarningsShow,
  useProfessorLedgerIndex,
  useProfessorPayoutsIndex,
} from "@yekkola/api-client/endpoints/earnings";
import { LedgerTransactionType } from "@yekkola/api-client/model";
import { formatDate, formatMoney, formatMonth, type Locale } from "@yekkola/i18n";
import { EmptyState } from "@yekkola/ui/blocks/empty-state";
import { KpiCard } from "@yekkola/ui/blocks/kpi-card";
import { Button } from "@yekkola/ui/components/button";
import { Input } from "@yekkola/ui/components/input";
import { Label } from "@yekkola/ui/components/label";
import { Skeleton } from "@yekkola/ui/components/skeleton";
import { Download } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useState } from "react";

import { useErrorText } from "../hooks";
import { selectClass } from "../styles";

/** Types a professor's statement can show (the API only returns lines on their own accounts). */
const TYPES = [
  LedgerTransactionType.sale,
  LedgerTransactionType.release,
  LedgerTransactionType.sale_reversal,
  LedgerTransactionType.refund,
  LedgerTransactionType.refund_reversal,
  LedgerTransactionType.payout,
  LedgerTransactionType.payout_failed,
  LedgerTransactionType.adjustment,
] as const;

/**
 * The professor's money (PRD-08 FR-09): what is held (refund window), available and paid out per currency, the
 * next payout run, earnings per month, the statement (filterable, downloadable as CSV) and past payouts. All
 * amounts come from the ledger through the API; nothing is computed here.
 */
export function EarningsPage() {
  const t = useTranslations("web.earnings");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const [type, setType] = useState("");
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [page, setPage] = useState(1);
  const [payoutPage, setPayoutPage] = useState(1);

  const earnings = useProfessorEarningsShow({ query: { select: (r) => success(r).data } });
  const filters = {
    ...(type ? { type: type as LedgerTransactionType } : {}),
    ...(from ? { from } : {}),
    ...(to ? { to } : {}),
  };
  const statement = useProfessorLedgerIndex(
    { ...filters, page, per_page: 20 },
    { query: { select: (r) => success(r), placeholderData: (previous) => previous } },
  );
  const payouts = useProfessorPayoutsIndex(
    { page: payoutPage, per_page: 10 },
    { query: { select: (r) => success(r), placeholderData: (previous) => previous } },
  );
  const csv = apiUrl(`/api/v1/professor/ledger.csv?${new URLSearchParams(filters).toString()}`);

  return (
    <div className="mx-auto grid max-w-5xl gap-8 px-4 py-8">
      <header className="grid gap-1">
        <h1 className="text-2xl font-extrabold">{t("title")}</h1>
        <p className="text-muted-foreground">{t("intro")}</p>
      </header>

      {earnings.isError ? (
        <EmptyState title={errorText(earnings.error)} />
      ) : (
        <section aria-labelledby="balances" className="grid gap-3">
          <h2 id="balances" className="font-bold">
            {t("balances")}
          </h2>
          {earnings.isPending ? (
            <Skeleton className="h-24 w-full" />
          ) : earnings.data.balances.length === 0 ? (
            <p className="text-sm text-muted-foreground">{t("noEarnings")}</p>
          ) : (
            earnings.data.balances.map((b) => (
              <div key={b.currency} className="grid gap-3 sm:grid-cols-4">
                <KpiCard
                  title={t("available")}
                  value={formatMoney({ amount_minor: b.available, currency: b.currency }, locale)}
                  hint={t("availableHint")}
                />
                <KpiCard
                  title={t("held")}
                  value={formatMoney({ amount_minor: b.held, currency: b.currency }, locale)}
                  hint={t("heldHint")}
                />
                <KpiCard
                  title={t("inPayout")}
                  value={formatMoney({ amount_minor: b.in_payout, currency: b.currency }, locale)}
                />
                <KpiCard
                  title={t("paidOut")}
                  value={formatMoney({ amount_minor: b.paid_out, currency: b.currency }, locale)}
                />
              </div>
            ))
          )}
          {earnings.data ? (
            <p className="text-sm">
              {t("nextPayout", { date: formatDate(earnings.data.next_payout_on, locale) })}
            </p>
          ) : null}
        </section>
      )}

      {earnings.data && earnings.data.monthly.length > 0 ? (
        <section aria-labelledby="monthly" className="grid gap-2">
          <h2 id="monthly" className="font-bold">
            {t("monthly")}
          </h2>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <caption className="sr-only">{t("monthly")}</caption>
              <thead className="text-left text-muted-foreground">
                <tr>
                  <th scope="col" className="py-1 pr-4 font-semibold">
                    {t("month")}
                  </th>
                  <th scope="col" className="py-1 text-right font-semibold">
                    {t("earned")}
                  </th>
                </tr>
              </thead>
              <tbody>
                {earnings.data.monthly.map((m) => (
                  <tr key={`${m.month}-${m.currency}`} className="border-t">
                    <td className="py-1.5 pr-4">{formatMonth(m.month, locale)}</td>
                    <td className="py-1.5 text-right tabular-nums">
                      {formatMoney({ amount_minor: m.amount_minor, currency: m.currency }, locale)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      ) : null}

      <section aria-labelledby="statement" className="grid gap-3">
        <div className="flex flex-wrap items-end justify-between gap-2">
          <h2 id="statement" className="font-bold">
            {t("statement")}
          </h2>
          <Button asChild variant="outline" size="sm">
            <a href={csv}>
              <Download aria-hidden className="size-4" />
              {t("downloadCsv")}
            </a>
          </Button>
        </div>
        <div className="grid gap-3 sm:grid-cols-3">
          <div className="grid gap-1.5">
            <Label htmlFor="statement-type">{t("type")}</Label>
            <select
              id="statement-type"
              className={selectClass}
              value={type}
              onChange={(e) => {
                setType(e.target.value);
                setPage(1);
              }}
            >
              <option value="">{t("allTypes")}</option>
              {TYPES.map((value) => (
                <option key={value} value={value}>
                  {t(`types.${value}`)}
                </option>
              ))}
            </select>
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="statement-from">{t("from")}</Label>
            <Input
              id="statement-from"
              type="date"
              value={from}
              onChange={(e) => {
                setFrom(e.target.value);
                setPage(1);
              }}
            />
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="statement-to">{t("to")}</Label>
            <Input
              id="statement-to"
              type="date"
              value={to}
              min={from || undefined}
              onChange={(e) => {
                setTo(e.target.value);
                setPage(1);
              }}
            />
          </div>
        </div>

        {statement.isError ? (
          <p role="alert" className="text-sm text-destructive">
            {errorText(statement.error)}
          </p>
        ) : statement.isPending ? (
          <Skeleton className="h-40 w-full" />
        ) : statement.data.data.length === 0 ? (
          <p className="text-sm text-muted-foreground">{t("noLines")}</p>
        ) : (
          <div className="overflow-x-auto" aria-busy={statement.isFetching}>
            <table className="w-full text-sm">
              <caption className="sr-only">{t("statement")}</caption>
              <thead className="text-left text-muted-foreground">
                <tr>
                  <th scope="col" className="py-1 pr-4 font-semibold">
                    {t("date")}
                  </th>
                  <th scope="col" className="py-1 pr-4 font-semibold">
                    {t("type")}
                  </th>
                  <th scope="col" className="py-1 pr-4 font-semibold">
                    {t("detail")}
                  </th>
                  <th scope="col" className="py-1 pr-4 font-semibold">
                    {t("balance")}
                  </th>
                  <th scope="col" className="py-1 text-right font-semibold">
                    {t("amount")}
                  </th>
                </tr>
              </thead>
              <tbody>
                {statement.data.data.map((line) => (
                  <tr key={line.id} className="border-t">
                    <td className="py-1.5 pr-4 whitespace-nowrap">{formatDate(line.date, locale)}</td>
                    <td className="py-1.5 pr-4">{t(`types.${line.type}` as never)}</td>
                    <td className="py-1.5 pr-4">{line.memo ?? "—"}</td>
                    <td className="py-1.5 pr-4">{t(`accounts.${line.account}` as never)}</td>
                    <td className="py-1.5 text-right tabular-nums">{formatMoney(line.amount, locale)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <Pager
          page={page}
          last={statement.data?.meta.last_page ?? 1}
          onPage={setPage}
          labels={{
            previous: t("previous"),
            next: t("next"),
            of: (p, n) => t("pageOf", { page: p, total: n }),
            nav: t("statement"),
          }}
        />
      </section>

      <section aria-labelledby="payouts" className="grid gap-3">
        <h2 id="payouts" className="font-bold">
          {t("payouts")}
        </h2>
        {payouts.isError ? (
          <p role="alert" className="text-sm text-destructive">
            {errorText(payouts.error)}
          </p>
        ) : payouts.isPending ? (
          <Skeleton className="h-24 w-full" />
        ) : payouts.data.data.length === 0 ? (
          <p className="text-sm text-muted-foreground">{t("noPayouts")}</p>
        ) : (
          <ul className="grid gap-1 text-sm">
            {payouts.data.data.map((p) => (
              <li
                key={p.id}
                className="flex flex-wrap items-center justify-between gap-2 rounded-md border px-3 py-2"
              >
                <span className="grid">
                  <span className="font-semibold tabular-nums">{formatMoney(p.amount, locale)}</span>
                  <span className="text-xs text-muted-foreground">
                    {p.reference} · {p.msisdn}
                  </span>
                </span>
                <span className="text-right text-muted-foreground">
                  {t(`payoutStatus.${p.status}` as never)}
                  {p.paid_at
                    ? ` · ${formatDate(p.paid_at, locale)}`
                    : p.sent_at
                      ? ` · ${formatDate(p.sent_at, locale)}`
                      : ""}
                  {p.status === "failed" && p.failure_message ? (
                    <span className="block text-xs text-destructive">{t("payoutFailed")}</span>
                  ) : null}
                </span>
              </li>
            ))}
          </ul>
        )}
        <Pager
          page={payoutPage}
          last={payouts.data?.meta.last_page ?? 1}
          onPage={setPayoutPage}
          labels={{
            previous: t("previous"),
            next: t("next"),
            of: (p, n) => t("pageOf", { page: p, total: n }),
            nav: t("payouts"),
          }}
        />
      </section>
    </div>
  );
}

function Pager({
  page,
  last,
  onPage,
  labels,
}: {
  page: number;
  last: number;
  onPage: (page: number) => void;
  labels: { previous: string; next: string; of: (page: number, total: number) => string; nav: string };
}) {
  if (last <= 1) return null;

  return (
    <nav className="flex items-center justify-between gap-2" aria-label={labels.nav}>
      <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => onPage(page - 1)}>
        {labels.previous}
      </Button>
      <span className="text-sm text-muted-foreground">{labels.of(page, last)}</span>
      <Button variant="outline" size="sm" disabled={page >= last} onClick={() => onPage(page + 1)}>
        {labels.next}
      </Button>
    </nav>
  );
}
