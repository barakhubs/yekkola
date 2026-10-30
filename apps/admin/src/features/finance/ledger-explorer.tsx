"use client";

import { success } from "@yekkola/api-client";
import {
  useAdminLedgerAccountsIndex,
  useAdminLedgerTransactionsIndex,
} from "@yekkola/api-client/endpoints/ledger";
import type { LedgerAccountResource, LedgerTransactionResource } from "@yekkola/api-client/model";
import { formatDateTime, formatMoney, type Locale } from "@yekkola/i18n";
import { DataTable, dataTableColumns } from "@yekkola/ui/blocks/data-table";
import { PageHeader } from "@yekkola/ui/blocks/page-header";
import { Button } from "@yekkola/ui/components/button";
import { Input } from "@yekkola/ui/components/input";
import { Label } from "@yekkola/ui/components/label";
import { cn } from "@yekkola/ui/lib/utils";
import { useLocale, useTranslations } from "next-intl";
import { parseAsInteger, parseAsString, useQueryState } from "nuqs";

import { useSession } from "@/features/auth/session";
import { useErrorText } from "@/features/common/errors";
import { selectClass } from "@/features/common/styles";
import { useTableLabels } from "@/features/common/table";

import { AdjustmentDialog } from "./adjustment-dialog";

const accountCol = dataTableColumns<LedgerAccountResource>();
const txCol = dataTableColumns<LedgerTransactionResource>();

const TRANSACTION_TYPES = [
  "sale",
  "sale_reversal",
  "release",
  "refund",
  "refund_reversal",
  "refund_paid",
  "payout",
  "payout_failed",
  "adjustment",
  "unapplied_receipt",
] as const;

/**
 * Ledger explorer (PRD-09 finance views, PRD-08): every account with its debits, credits and balance, and the
 * transactions with their entries. Read-only; a professor's available balance is corrected with an adjustment
 * (finance.adjustments). View, filters and page live in the URL.
 */
export function LedgerExplorer() {
  const t = useTranslations("admin.ledger");
  const [view, setView] = useQueryState("view", parseAsString.withDefault("accounts"));
  const [account, setAccount] = useQueryState("account", parseAsString);
  const [, setPage] = useQueryState("page", parseAsInteger.withDefault(1));

  const show = (next: string, accountId: string | null = null) => {
    void setView(next);
    void setAccount(accountId);
    void setPage(1);
  };

  return (
    <div className="mx-auto grid max-w-6xl gap-6 px-4 py-8 sm:px-6">
      <PageHeader title={t("title")} description={t("intro")} />
      <div role="tablist" aria-label={t("title")} className="flex gap-1 border-b">
        {(["accounts", "transactions"] as const).map((tab) => (
          <button
            key={tab}
            role="tab"
            type="button"
            aria-selected={view === tab}
            onClick={() => show(tab)}
            className={cn(
              "-mb-px border-b-2 px-3 py-2 text-sm font-semibold",
              view === tab
                ? "border-primary text-primary"
                : "border-transparent text-muted-foreground hover:text-foreground",
            )}
          >
            {t(`tabs.${tab}`)}
          </button>
        ))}
      </div>
      {view === "transactions" ? (
        <Transactions account={account} onClearAccount={() => void setAccount(null)} />
      ) : (
        <Accounts onTransactions={(id) => show("transactions", id)} />
      )}
    </div>
  );
}

function Accounts({ onTransactions }: { onTransactions: (accountId: string) => void }) {
  const t = useTranslations("admin.ledger");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const labels = useTableLabels();
  const session = useSession();
  const [owner, setOwner] = useQueryState("owner", parseAsString.withDefault("all"));
  const [page, setPage] = useQueryState("page", parseAsInteger.withDefault(1));

  const accounts = useAdminLedgerAccountsIndex(
    { page, per_page: 50, ...(owner === "all" ? {} : { "filter[owner_type]": owner }) },
    { query: { select: (r) => success(r), placeholderData: (previous) => previous } },
  );
  const money = (minor: number, currency: string) => formatMoney({ amount_minor: minor, currency }, locale);

  const columns = accountCol.columns([
    accountCol.accessor((row) => row.owner_name ?? t("platform"), {
      id: "owner",
      header: t("columns.owner"),
      enableSorting: false,
    }),
    accountCol.accessor("type", {
      header: t("columns.account"),
      enableSorting: false,
      cell: ({ getValue }) => t(`accounts.${getValue()}` as never),
    }),
    accountCol.accessor("debits_minor", {
      header: t("columns.debits"),
      enableSorting: false,
      cell: ({ row }) => (
        <span className="tabular-nums">{money(row.original.debits_minor, row.original.currency)}</span>
      ),
    }),
    accountCol.accessor("credits_minor", {
      header: t("columns.credits"),
      enableSorting: false,
      cell: ({ row }) => (
        <span className="tabular-nums">{money(row.original.credits_minor, row.original.currency)}</span>
      ),
    }),
    accountCol.accessor("balance_minor", {
      header: t("columns.balance"),
      enableSorting: false,
      cell: ({ row }) => (
        <span className="font-semibold tabular-nums">
          {money(row.original.balance_minor, row.original.currency)}
        </span>
      ),
    }),
    accountCol.display({
      id: "actions",
      header: () => <span className="sr-only">{t("columns.actions")}</span>,
      cell: ({ row }) => (
        <span className="flex justify-end gap-1">
          <Button size="sm" variant="ghost" onClick={() => onTransactions(row.original.id)}>
            {t("seeTransactions")}
          </Button>
          {row.original.type === "professor_available" &&
          row.original.owner_id &&
          session.can("finance.adjustments") ? (
            <AdjustmentDialog
              professorId={row.original.owner_id}
              professorName={row.original.owner_name ?? ""}
              currency={row.original.currency}
            />
          ) : null}
        </span>
      ),
    }),
  ]);

  return (
    <>
      <div className="grid max-w-xs gap-2">
        <Label htmlFor="ledger-owner">{t("filterOwner")}</Label>
        <select
          id="ledger-owner"
          className={selectClass}
          value={owner}
          onChange={(e) => {
            void setOwner(e.target.value);
            void setPage(1);
          }}
        >
          <option value="all">{t("allOwners")}</option>
          <option value="platform">{t("platform")}</option>
          <option value="professor">{t("professors")}</option>
        </select>
      </div>
      <p className="text-xs text-muted-foreground">{t("balanceHint")}</p>
      {accounts.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(accounts.error)}
        </p>
      ) : (
        <DataTable
          columns={columns}
          data={accounts.data?.data ?? []}
          getRowId={(row) => row.id}
          caption={t("tabs.accounts")}
          page={page}
          pageCount={accounts.data?.meta.last_page ?? 1}
          onPageChange={(next) => void setPage(next)}
          loading={accounts.isFetching}
          empty={t("emptyAccounts")}
          labels={labels}
        />
      )}
    </>
  );
}

function Transactions({ account, onClearAccount }: { account: string | null; onClearAccount: () => void }) {
  const t = useTranslations("admin.ledger");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const labels = useTableLabels();
  const [type, setType] = useQueryState("type", parseAsString.withDefault("all"));
  const [reference, setReference] = useQueryState("reference", parseAsString.withDefault(""));
  const [from, setFrom] = useQueryState("from", parseAsString.withDefault(""));
  const [to, setTo] = useQueryState("to", parseAsString.withDefault(""));
  const [page, setPage] = useQueryState("page", parseAsInteger.withDefault(1));

  const transactions = useAdminLedgerTransactionsIndex(
    {
      page,
      per_page: 25,
      ...(type === "all" ? {} : { "filter[type]": type }),
      ...(account ? { "filter[account_id]": account } : {}),
      ...(reference ? { "filter[reference_id]": reference } : {}),
      ...(from ? { "filter[from]": from } : {}),
      ...(to ? { "filter[to]": to } : {}),
    },
    { query: { select: (r) => success(r), placeholderData: (previous) => previous } },
  );

  const columns = txCol.columns([
    txCol.accessor("created_at", {
      header: t("columns.date"),
      enableSorting: false,
      cell: ({ getValue }) => <span className="whitespace-nowrap">{formatDateTime(getValue(), locale)}</span>,
    }),
    txCol.accessor("type", {
      header: t("columns.type"),
      enableSorting: false,
      cell: ({ row }) => (
        <span className="grid">
          <span className="font-semibold">{t(`types.${row.original.type}` as never)}</span>
          {row.original.memo ? (
            <span className="text-xs text-muted-foreground">{row.original.memo}</span>
          ) : null}
        </span>
      ),
    }),
    txCol.accessor("entries", {
      header: t("columns.entries"),
      enableSorting: false,
      cell: ({ getValue }) => (
        <ul className="grid gap-0.5 text-xs">
          {getValue().map((e, i) => (
            <li key={i} className="flex justify-between gap-4">
              <span>
                {t(`accounts.${e.account_type}` as never)}
                {e.owner_name ? ` · ${e.owner_name}` : ""}
              </span>
              <span className="tabular-nums">
                {e.direction === "debit" ? t("debit") : t("credit")}{" "}
                {formatMoney({ amount_minor: e.amount_minor, currency: e.currency }, locale)}
              </span>
            </li>
          ))}
        </ul>
      ),
    }),
    txCol.accessor("reference_id", {
      header: t("columns.reference"),
      enableSorting: false,
      cell: ({ row }) => (
        <span className="font-mono text-xs break-all">
          {row.original.reference_type} {row.original.reference_id}
        </span>
      ),
    }),
  ]);

  const field = (id: string, label: string, input: React.ReactNode) => (
    <div className="grid gap-2">
      <Label htmlFor={id}>{label}</Label>
      {input}
    </div>
  );

  return (
    <>
      <div className="grid gap-4 sm:grid-cols-4">
        {field(
          "tx-type",
          t("columns.type"),
          <select
            id="tx-type"
            className={selectClass}
            value={type}
            onChange={(e) => {
              void setType(e.target.value);
              void setPage(1);
            }}
          >
            <option value="all">{t("allTypes")}</option>
            {TRANSACTION_TYPES.map((value) => (
              <option key={value} value={value}>
                {t(`types.${value}`)}
              </option>
            ))}
          </select>,
        )}
        {field(
          "tx-reference",
          t("columns.reference"),
          <Input
            id="tx-reference"
            value={reference}
            onChange={(e) => {
              void setReference(e.target.value.trim() || null);
              void setPage(1);
            }}
          />,
        )}
        {field(
          "tx-from",
          t("from"),
          <Input
            id="tx-from"
            type="date"
            value={from}
            onChange={(e) => void setFrom(e.target.value || null)}
          />,
        )}
        {field(
          "tx-to",
          t("to"),
          <Input id="tx-to" type="date" value={to} onChange={(e) => void setTo(e.target.value || null)} />,
        )}
      </div>
      {account ? (
        <p className="flex items-center gap-2 text-sm">
          {t("oneAccount")}
          <Button size="sm" variant="link" className="h-auto p-0" onClick={onClearAccount}>
            {t("allAccounts")}
          </Button>
        </p>
      ) : null}
      {transactions.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(transactions.error)}
        </p>
      ) : (
        <DataTable
          columns={columns}
          data={transactions.data?.data ?? []}
          getRowId={(row) => row.id}
          caption={t("tabs.transactions")}
          page={page}
          pageCount={transactions.data?.meta.last_page ?? 1}
          onPageChange={(next) => void setPage(next)}
          loading={transactions.isFetching}
          empty={t("emptyTransactions")}
          labels={labels}
        />
      )}
    </>
  );
}
