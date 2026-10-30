"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { adminLedgerAdjustmentsStore } from "@yekkola/api-client/endpoints/ledger-adjustment";
import {
  getAdminLedgerAccountsIndexQueryKey,
  getAdminLedgerTransactionsIndexQueryKey,
} from "@yekkola/api-client/endpoints/ledger";
import type { Currency } from "@yekkola/api-client/model";
import { parseMoneyInput } from "@yekkola/i18n";
import { Button } from "@yekkola/ui/components/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@yekkola/ui/components/dialog";
import { Input } from "@yekkola/ui/components/input";
import { Label } from "@yekkola/ui/components/label";
import { Textarea } from "@yekkola/ui/components/textarea";
import { useTranslations } from "next-intl";
import { type FormEvent, useState } from "react";

import { useErrorText } from "@/features/common/errors";

/**
 * Manual adjustment of a professor's available balance (PRD-08 FR-11): credit (the platform owes more) or debit
 * (taken back), a label the professor sees on their statement, and an internal reason for the audit log. Each
 * opening is one request: a retry after a lost answer reuses its `Idempotency-Key`, so it can't post twice.
 */
export function AdjustmentDialog({
  professorId,
  professorName,
  currency,
}: {
  professorId: string;
  professorName: string;
  currency: string;
}) {
  const t = useTranslations("admin.ledger.adjust");
  const common = useTranslations("common");
  const errorText = useErrorText();
  const queryClient = useQueryClient();
  const [open, setOpen] = useState(false);
  const [key, setKey] = useState(() => crypto.randomUUID());
  const [direction, setDirection] = useState<"credit" | "debit">("credit");
  const [amount, setAmount] = useState("");
  const [label, setLabel] = useState("");
  const [reason, setReason] = useState("");
  const minor = parseMoneyInput(amount, currency);
  const valid = minor !== null && minor > 0 && label.trim().length >= 3 && reason.trim().length >= 3;

  const save = useMutation({
    mutationFn: () =>
      adminLedgerAdjustmentsStore(
        {
          professor_profile_id: professorId,
          currency: currency as Currency,
          amount_minor: minor ?? 0,
          direction,
          label: label.trim(),
          reason: reason.trim(),
        },
        { headers: { "Idempotency-Key": key } },
      ),
    onSuccess: async () => {
      setOpen(false);
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: getAdminLedgerAccountsIndexQueryKey() }),
        queryClient.invalidateQueries({ queryKey: getAdminLedgerTransactionsIndexQueryKey() }),
      ]);
    },
  });

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next);
        if (next) {
          // A new adjustment: new key, clean form.
          setKey(crypto.randomUUID());
          setAmount("");
          setLabel("");
          setReason("");
          setDirection("credit");
          save.reset();
        }
      }}
    >
      <DialogTrigger asChild>
        <Button size="sm" variant="outline">
          {t("open")}
        </Button>
      </DialogTrigger>
      <DialogContent closeLabel={common("close")}>
        <DialogHeader>
          <DialogTitle>{t("title", { name: professorName })}</DialogTitle>
          <DialogDescription>{t("hint")}</DialogDescription>
        </DialogHeader>
        <form
          noValidate
          className="grid gap-4"
          onSubmit={(e: FormEvent) => {
            e.preventDefault();
            if (valid && !save.isPending) save.mutate();
          }}
        >
          <fieldset className="grid gap-2">
            <legend className="mb-1 text-sm font-semibold">{t("direction")}</legend>
            {(["credit", "debit"] as const).map((value) => (
              <label key={value} className="flex items-center gap-2 text-sm">
                <input
                  type="radio"
                  name="direction"
                  value={value}
                  checked={direction === value}
                  onChange={() => setDirection(value)}
                  className="size-4 accent-primary"
                />
                {t(`directions.${value}`)}
              </label>
            ))}
          </fieldset>
          <div className="grid gap-2">
            <Label htmlFor="adjust-amount">{t("amount", { currency })}</Label>
            <Input
              id="adjust-amount"
              inputMode="decimal"
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
            />
            {amount && (minor === null || minor <= 0) ? (
              <p className="text-sm text-destructive">{t("badAmount")}</p>
            ) : null}
          </div>
          <div className="grid gap-2">
            <Label htmlFor="adjust-label">{t("label")}</Label>
            <Input
              id="adjust-label"
              maxLength={200}
              value={label}
              onChange={(e) => setLabel(e.target.value)}
            />
            <p className="text-xs text-muted-foreground">{t("labelHint")}</p>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="adjust-reason">{t("reason")}</Label>
            <Textarea
              id="adjust-reason"
              rows={3}
              maxLength={2000}
              value={reason}
              onChange={(e) => setReason(e.target.value)}
            />
            <p className="text-xs text-muted-foreground">{t("reasonHint")}</p>
          </div>
          {save.isError ? (
            <p role="alert" className="text-sm text-destructive">
              {errorText(save.error)}
            </p>
          ) : null}
          <div className="flex justify-end gap-2">
            <Button type="button" variant="ghost" onClick={() => setOpen(false)}>
              {common("cancel")}
            </Button>
            <Button type="submit" disabled={!valid || save.isPending} aria-busy={save.isPending}>
              {t("save")}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  );
}
