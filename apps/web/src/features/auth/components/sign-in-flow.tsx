"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { ApiError, success } from "@yekkola/api-client";
import { authOtpRequest, authOtpVerify } from "@yekkola/api-client/endpoints/otp";
import { meUpdate } from "@yekkola/api-client/endpoints/profile";
import { useProvincesIndex } from "@yekkola/api-client/endpoints/province";
import type { UserResource } from "@yekkola/api-client/model";
import { formatPhone } from "@yekkola/i18n";
import { Button } from "@yekkola/ui/components/button";
import { Input } from "@yekkola/ui/components/input";
import { Label } from "@yekkola/ui/components/label";
import { useLocale, useTranslations } from "next-intl";
import { useSearchParams } from "next/navigation";
import { type FormEvent, useEffect, useState } from "react";

import { useRouter } from "@/i18n/navigation";

import { safeNext } from "../safe-next";
import { Turnstile } from "./turnstile";

type Step = { name: "phone" } | { name: "code"; phone: string; resendAt: number } | { name: "profile" };

const selectClass =
  "h-10 w-full rounded-md border border-input bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none";

/** How the typed number reads back ("0812345678" → "+243 812 345 678"); display only — the API normalises. */
function displayPhone(raw: string): string {
  const digits = raw.replace(/\D/g, "");
  if (raw.trim().startsWith("+")) return formatPhone(`+${digits}`);
  if (digits.startsWith("243")) return formatPhone(`+${digits}`);
  if (digits.startsWith("0")) return formatPhone(`+243${digits.slice(1)}`);
  return formatPhone(`+243${digits}`);
}

/** The API's translated message (it reads Accept-Language), or a generic one. */
function useErrorMessage() {
  const t = useTranslations("errors");
  return (error: unknown): string => {
    if (error instanceof ApiError)
      return error.fieldErrors().phone ?? error.fieldErrors().code ?? error.body.message ?? t("generic");
    return t("network");
  };
}

/**
 * Phone → SMS code → (first time) name (PRD-01 FR-01–FR-05). Signs in with the Sanctum session cookie,
 * then returns to `?next=` (same-site paths only).
 */
export function SignInFlow() {
  const t = useTranslations("web.auth");
  const locale = useLocale();
  const router = useRouter();
  const next = safeNext(useSearchParams().get("next"));
  const queryClient = useQueryClient();
  const errorMessage = useErrorMessage();
  const [step, setStep] = useState<Step>({ name: "phone" });
  const [phone, setPhone] = useState("");
  const [code, setCode] = useState("");
  const [botToken, setBotToken] = useState<string | null>(null);

  const finish = async (user: UserResource) => {
    queryClient.setQueryData(["/me"], { data: { data: user }, status: 200, headers: new Headers() });
    await queryClient.invalidateQueries();
    router.replace(next);
  };

  const requestCode = useMutation({
    mutationFn: async (to: string) =>
      success(await authOtpRequest({ phone: to, bot_token: botToken ?? undefined })).data,
    onSuccess: (challenge, to) =>
      setStep({ name: "code", phone: to, resendAt: Date.now() + challenge.resend_after_seconds * 1000 }),
  });

  const verify = useMutation({
    mutationFn: async ({ to, digits }: { to: string; digits: string }) =>
      success(await authOtpVerify({ phone: to, code: digits })).data,
    onSuccess: async (result) => {
      const user = result.user as UserResource;
      if (!user.name) {
        setStep({ name: "profile" });
      } else {
        await finish(user);
      }
    },
    onError: () => setCode(""),
  });

  if (step.name === "profile") {
    return <ProfileStep onDone={finish} />;
  }

  if (step.name === "code") {
    const submit = (digits: string) => {
      if (digits.length === 6 && !verify.isPending) verify.mutate({ to: step.phone, digits });
    };

    return (
      <form
        className="grid gap-4"
        onSubmit={(e: FormEvent) => {
          e.preventDefault();
          submit(code);
        }}
      >
        <p className="text-sm text-muted-foreground">
          {t("codeSentTo", { phone: displayPhone(step.phone) })}
        </p>
        <div className="grid gap-2">
          <Label htmlFor="otp-code">{t("codeLabel")}</Label>
          <Input
            id="otp-code"
            inputMode="numeric"
            autoComplete="one-time-code"
            pattern="[0-9]{6}"
            maxLength={6}
            autoFocus
            value={code}
            aria-invalid={verify.isError || undefined}
            aria-describedby={verify.isError ? "otp-error" : undefined}
            onChange={(e) => {
              const digits = e.target.value.replace(/\D/g, "").slice(0, 6);
              setCode(digits);
              submit(digits); // auto-advance on 6 digits (PRD-01 §6)
            }}
            className="h-12 text-center text-2xl tracking-[0.5em] tabular-nums"
          />
          {verify.isError ? (
            <p id="otp-error" role="alert" className="text-sm text-destructive">
              {errorMessage(verify.error)}
            </p>
          ) : null}
        </div>
        <Button
          type="submit"
          size="lg"
          disabled={code.length !== 6 || verify.isPending}
          aria-busy={verify.isPending}
        >
          {t("verify")}
        </Button>
        <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
          <ResendButton
            resendAt={step.resendAt}
            pending={requestCode.isPending}
            onResend={() => requestCode.mutate(step.phone)}
          />
          <Button
            variant="link"
            type="button"
            className="h-auto p-0"
            onClick={() => setStep({ name: "phone" })}
          >
            {t("changeNumber")}
          </Button>
        </div>
        {requestCode.isError ? (
          <p role="alert" className="text-sm text-destructive">
            {errorMessage(requestCode.error)}
          </p>
        ) : null}
      </form>
    );
  }

  return (
    <form
      className="grid gap-4"
      onSubmit={(e: FormEvent) => {
        e.preventDefault();
        if (phone.trim() && botToken) requestCode.mutate(phone.trim());
      }}
    >
      <div className="grid gap-2">
        <Label htmlFor="phone">{t("phoneLabel")}</Label>
        <div className="flex">
          <span className="inline-flex items-center rounded-l-md border border-r-0 border-input bg-muted px-3 text-sm font-semibold">
            +243
          </span>
          <Input
            id="phone"
            type="tel"
            inputMode="tel"
            autoComplete="tel"
            required
            value={phone}
            placeholder={t("phoneHint")}
            aria-invalid={requestCode.isError || undefined}
            aria-describedby={requestCode.isError ? "phone-error" : "phone-hint"}
            onChange={(e) => setPhone(e.target.value)}
            className="rounded-l-none"
          />
        </div>
        <p id="phone-hint" className="text-xs text-muted-foreground">
          {t("phoneHint")}
        </p>
        {requestCode.isError ? (
          <p id="phone-error" role="alert" className="text-sm text-destructive">
            {errorMessage(requestCode.error)}
          </p>
        ) : null}
      </div>
      <Turnstile onToken={setBotToken} locale={locale} label={t("botCheck")} />
      <Button
        type="submit"
        size="lg"
        disabled={!botToken || requestCode.isPending}
        aria-busy={requestCode.isPending}
      >
        {t("sendCode")}
      </Button>
    </form>
  );
}

function ResendButton({
  resendAt,
  pending,
  onResend,
}: {
  resendAt: number;
  pending: boolean;
  onResend: () => void;
}) {
  const t = useTranslations("web.auth");
  const [now, setNow] = useState(() => Date.now());

  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(timer);
  }, []);

  const seconds = Math.max(0, Math.ceil((resendAt - now) / 1000));

  return seconds > 0 ? (
    <span className="text-muted-foreground tabular-nums" aria-live="polite">
      {t("resendIn", { seconds })}
    </span>
  ) : (
    <Button variant="link" type="button" className="h-auto p-0" disabled={pending} onClick={onResend}>
      {t("resend")}
    </Button>
  );
}

/** First sign-in: name (required, PRD-01 FR-04) and an optional province — never a default city or province. */
function ProfileStep({ onDone }: { onDone: (user: UserResource) => Promise<void> }) {
  const t = useTranslations("web.auth");
  const locale = useLocale();
  const errorMessage = useErrorMessage();
  const provinces = useProvincesIndex({ query: { select: (r) => success(r).data } });
  const [name, setName] = useState("");
  const [provinceId, setProvinceId] = useState("");

  const save = useMutation({
    mutationFn: async () =>
      success(
        await meUpdate({
          name: name.trim(),
          locale: locale === "en" ? "en" : "fr",
          province_id: provinceId || null,
        }),
      ).data as UserResource,
    onSuccess: (updated) => onDone(updated),
  });
  const nameError = save.error instanceof ApiError ? save.error.fieldErrors().name : undefined;

  return (
    <form
      className="grid gap-4"
      onSubmit={(e: FormEvent) => {
        e.preventDefault();
        save.mutate();
      }}
    >
      <div className="grid gap-1">
        <h2 className="text-xl font-bold">{t("profileTitle")}</h2>
        <p className="text-sm text-muted-foreground">{t("profileSubtitle")}</p>
      </div>
      <div className="grid gap-2">
        <Label htmlFor="name">{t("nameLabel")}</Label>
        <Input
          id="name"
          autoComplete="name"
          required
          minLength={2}
          maxLength={100}
          autoFocus
          value={name}
          aria-invalid={Boolean(nameError) || undefined}
          aria-describedby={nameError ? "name-error" : undefined}
          onChange={(e) => setName(e.target.value)}
        />
        {nameError ? (
          <p id="name-error" role="alert" className="text-sm text-destructive">
            {nameError}
          </p>
        ) : null}
      </div>
      <div className="grid gap-2">
        <Label htmlFor="province">{t("provinceLabel")}</Label>
        <select
          id="province"
          value={provinceId}
          onChange={(e) => setProvinceId(e.target.value)}
          className={selectClass}
        >
          <option value="">{t("provinceNone")}</option>
          {(provinces.data ?? []).map((province) => (
            <option key={province.id} value={province.id}>
              {province.name}
            </option>
          ))}
        </select>
      </div>
      {save.isError && !nameError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorMessage(save.error)}
        </p>
      ) : null}
      <Button
        type="submit"
        size="lg"
        disabled={name.trim().length < 2 || save.isPending}
        aria-busy={save.isPending}
      >
        {t("continue")}
      </Button>
    </form>
  );
}
