"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { success } from "@yekkola/api-client";
import {
  professorPayoutMethodOtp,
  professorPayoutMethodUpdate,
} from "@yekkola/api-client/endpoints/payout-method";
import { getProfessorProfileShowQueryKey } from "@yekkola/api-client/endpoints/studio-profile";
import type { MobileMoneyRail, ProfessorProfileResource } from "@yekkola/api-client/model";
import { formatDateTime, formatPhone, type Locale } from "@yekkola/i18n";
import { PageHeader } from "@yekkola/ui/blocks/page-header";
import { Button } from "@yekkola/ui/components/button";
import { Input } from "@yekkola/ui/components/input";
import { Label } from "@yekkola/ui/components/label";
import { Wallet } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { type FormEvent, useState } from "react";

import { useSession } from "@/features/auth/hooks/use-session";
import { RailPicker } from "@/features/checkout/components/rail-picker";

import { fieldErrorsOf, useErrorText } from "../hooks";
import { useStudioProfile } from "./professor-shell";

const ALL_RAILS = { orange: true, airtel: true, mpesa: true };

/**
 * Payout method (PRD-02 FR-09): operator, number and account name, confirmed by a code sent to the account's own
 * phone. A change starts a 48-hour payout hold (the API decides and reports it).
 */
export function PayoutForm() {
  const profile = useStudioProfile();

  return profile.data ? <Editor profile={profile.data} /> : null;
}

function Editor({ profile }: { profile: ProfessorProfileResource }) {
  const t = useTranslations("web.onboarding");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const queryClient = useQueryClient();
  const { user } = useSession();
  const current = profile.payout_method;
  const [editing, setEditing] = useState(current === null);
  const [rail, setRail] = useState<MobileMoneyRail | null>((current?.rail as MobileMoneyRail | null) ?? null);
  const [phone, setPhone] = useState("");
  const [name, setName] = useState(current?.name ?? "");
  const [codeSent, setCodeSent] = useState(false);
  const [code, setCode] = useState("");

  const requestCode = useMutation({
    mutationFn: () => professorPayoutMethodOtp(),
    onSuccess: () => setCodeSent(true),
  });
  const save = useMutation({
    mutationFn: async () =>
      success(
        await professorPayoutMethodUpdate({
          rail: rail as MobileMoneyRail,
          phone: phone.replace(/\s/g, ""),
          name: name.trim(),
          code,
        }),
      ).data,
    onSuccess: (saved) => {
      queryClient.setQueryData(getProfessorProfileShowQueryKey(), saved);
      setEditing(false);
      setCodeSent(false);
      setCode("");
    },
  });
  const errors = fieldErrorsOf(save.error);

  return (
    <div className="mx-auto grid max-w-3xl gap-6 px-4 py-8">
      <PageHeader title={t("payoutTitle")} description={t("payoutIntro")} />

      {current ? (
        <section className="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-background p-4">
          <span className="flex items-start gap-3">
            <Wallet aria-hidden className="mt-0.5 size-5 text-primary" />
            <span className="grid">
              <span className="font-semibold">
                {current.rail ? t(`rails.${current.rail as MobileMoneyRail}`) : ""} · {current.phone}
              </span>
              <span className="text-sm text-muted-foreground">{current.name}</span>
            </span>
          </span>
          {editing ? null : (
            <Button variant="outline" size="sm" onClick={() => setEditing(true)}>
              {t("change")}
            </Button>
          )}
        </section>
      ) : null}
      {profile.payout_hold_until ? (
        <p className="text-sm">{t("onHold", { date: formatDateTime(profile.payout_hold_until, locale) })}</p>
      ) : null}

      {editing ? (
        <form
          noValidate
          className="grid gap-4 rounded-xl border bg-background p-4 sm:p-6"
          onSubmit={(e: FormEvent) => {
            e.preventDefault();
            if (!codeSent) requestCode.mutate();
            else if (!save.isPending) save.mutate();
          }}
        >
          <RailPicker available={ALL_RAILS} value={rail} onChange={setRail} />
          {errors.rail ? <p className="text-sm text-destructive">{errors.rail}</p> : null}
          <div className="grid gap-2">
            <Label htmlFor="payout-phone">{t("fields.payoutPhone")}</Label>
            <Input
              id="payout-phone"
              type="tel"
              inputMode="tel"
              value={phone}
              onChange={(e) => setPhone(e.target.value)}
            />
            {errors.phone ? <p className="text-sm text-destructive">{errors.phone}</p> : null}
          </div>
          <div className="grid gap-2">
            <Label htmlFor="payout-name">{t("fields.payoutName")}</Label>
            <Input id="payout-name" value={name} onChange={(e) => setName(e.target.value)} />
            {errors.name ? <p className="text-sm text-destructive">{errors.name}</p> : null}
          </div>

          {codeSent ? (
            <div className="grid gap-2">
              <Label htmlFor="payout-code">{t("fields.code")}</Label>
              <p className="text-xs text-muted-foreground">
                {t("codeSent", { phone: user?.phone ? formatPhone(user.phone) : "" })}
              </p>
              <Input
                id="payout-code"
                inputMode="numeric"
                autoComplete="one-time-code"
                maxLength={6}
                className="max-w-40 tracking-[0.3em]"
                value={code}
                onChange={(e) => setCode(e.target.value.replace(/\D/g, ""))}
              />
              {errors.code ? <p className="text-sm text-destructive">{errors.code}</p> : null}
            </div>
          ) : null}

          <p className="text-xs text-muted-foreground">{t("holdNotice")}</p>
          <div className="flex flex-wrap items-center gap-3">
            <Button
              type="submit"
              disabled={!rail || !phone.trim() || !name.trim() || requestCode.isPending || save.isPending}
            >
              {codeSent ? t("confirm") : t("sendCode")}
            </Button>
            {requestCode.isError || (save.isError && Object.keys(errors).length === 0) ? (
              <p role="alert" className="text-sm text-destructive">
                {errorText(requestCode.error ?? save.error)}
              </p>
            ) : null}
          </div>
        </form>
      ) : null}
    </div>
  );
}
