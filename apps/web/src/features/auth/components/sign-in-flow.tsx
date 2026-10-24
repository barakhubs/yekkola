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
import { type FormEvent, useEffect, useRef, useState } from "react";

import { useRouter } from "@/i18n/navigation";

import { useSession } from "../hooks/use-session";
import { safeNext } from "../safe-next";
import { Turnstile, type TurnstileHandle } from "./turnstile";

type Step = { name: "phone" } | { name: "code"; phone: string; resendAt: number } | { name: "profile" };

/** Codes after which this challenge can't succeed: offer a new code straight away. */
const DEAD_CHALLENGE = new Set(["otp.expired", "otp.too_many_attempts"]);

const selectClass =
  "h-10 w-full rounded-md border border-input bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none";

/** How the typed number reads back ("0812345678" → "+243 812 345 678"); display only — the API normalises. */
function displayPhone(raw: string): string {
  const digits = raw.replace(/\D/g, "");
  if (raw.trim().startsWith("+") || digits.startsWith("243")) return formatPhone(`+${digits}`);
  return formatPhone(`+243${digits.startsWith("0") ? digits.slice(1) : digits}`);
}

/** Groups digits as they're typed ("0812 345 678"); keeps a leading "+". */
function groupDigits(value: string): string {
  const plus = value.trim().startsWith("+") ? "+" : "";
  const digits = value.replace(/\D/g, "").slice(0, 13);
  const groups = [digits.slice(0, 4), digits.slice(4, 7), digits.slice(7, 10), digits.slice(10)].filter(
    Boolean,
  );
  return plus + groups.join(" ");
}

/** The API's translated message (it reads Accept-Language), or a generic one. */
function useErrorMessage() {
  const t = useTranslations("errors");
  return (error: unknown): string => {
    if (error instanceof ApiError) {
      const fields = error.fieldErrors();
      return fields.phone ?? fields.code ?? fields.name ?? error.body.message ?? t("generic");
    }
    return t("network");
  };
}

/**
 * Phone → SMS code → (first time) name (PRD-01 FR-01–FR-05). Signs in with the Sanctum session cookie,
 * then returns to `?next=` (same-site paths only). Bot-check tokens are single-use: a fresh one is required
 * for every code request, including resends.
 */
export function SignInFlow() {
  const t = useTranslations("web.auth");
  const locale = useLocale();
  const router = useRouter();
  const params = useSearchParams();
  const next = safeNext(params.get("next"));
  const queryClient = useQueryClient();
  const session = useSession();
  const errorMessage = useErrorMessage();
  const turnstile = useRef<TurnstileHandle>(null);
  const codeInput = useRef<HTMLInputElement>(null);
  const [step, setStep] = useState<Step>(() =>
    params.get("step") === "profile" ? { name: "profile" } : { name: "phone" },
  );
  const [phone, setPhone] = useState("");
  const [code, setCode] = useState("");
  const [botToken, setBotToken] = useState<string | null>(null);

  const finish = async (user: UserResource) => {
    queryClient.setQueryData(["/me"], { data: { data: user }, status: 200, headers: new Headers() });
    await queryClient.invalidateQueries();
    // Suspended accounts land where the explanation is shown (FR-15).
    router.replace(user.status === "suspended" ? "/my-courses" : next);
  };

  // Already signed in (e.g. back button): finish the profile if needed, otherwise go on.
  const showProfile = step.name === "profile" || (step.name === "phone" && session.needsProfile);
  useEffect(() => {
    if (session.user && !session.needsProfile && step.name === "phone") {
      router.replace(next);
    }
  }, [session.user, session.needsProfile, step.name, next, router]);

  const requestCode = useMutation({
    mutationFn: async (to: string) =>
      success(await authOtpRequest({ phone: to, bot_token: botToken ?? undefined })).data,
    onSuccess: (challenge, to) =>
      setStep({ name: "code", phone: to, resendAt: Date.now() + challenge.resend_after_seconds * 1000 }),
    // The token is spent either way: get a new one for the next attempt.
    onSettled: () => turnstile.current?.reset(),
  });

  const verify = useMutation({
    mutationFn: async ({ to, digits }: { to: string; digits: string }) =>
      success(await authOtpVerify({ phone: to, code: digits })).data,
    onSuccess: async (result) => {
      const user = result.user as UserResource;
      if (!user.name) {
        queryClient.setQueryData(["/me"], { data: { data: user }, status: 200, headers: new Headers() });
        setStep({ name: "profile" });
      } else {
        await finish(user);
      }
    },
    onError: (error) => {
      setCode("");
      if (step.name === "code" && error instanceof ApiError && DEAD_CHALLENGE.has(error.code ?? "")) {
        setStep({ ...step, resendAt: 0 });
      }
      requestAnimationFrame(() => codeInput.current?.focus());
    },
  });

  if (showProfile) {
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
            ref={codeInput}
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
        <Turnstile ref={turnstile} onToken={setBotToken} locale={locale} />
        <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
          <ResendButton
            resendAt={step.resendAt}
            disabled={requestCode.isPending || !botToken}
            onResend={() => requestCode.mutate(step.phone)}
          />
          <Button
            variant="link"
            type="button"
            className="h-auto p-0"
            onClick={() => {
              requestCode.reset();
              verify.reset();
              setCode("");
              setStep({ name: "phone" });
            }}
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

  const phoneError = requestCode.isError ? errorMessage(requestCode.error) : null;

  return (
    <form
      className="grid gap-4"
      onSubmit={(e: FormEvent) => {
        e.preventDefault();
        if (phone.trim() && botToken) requestCode.mutate(phone.replace(/\s/g, ""));
      }}
    >
      <div className="grid gap-2">
        <Label htmlFor="phone">{t("phoneLabel")}</Label>
        <div className="flex">
          <span
            aria-hidden
            className="inline-flex items-center rounded-l-md border border-r-0 border-input bg-muted px-3 text-sm font-semibold"
          >
            +243
          </span>
          <Input
            id="phone"
            type="tel"
            inputMode="tel"
            autoComplete="tel"
            required
            value={phone}
            aria-invalid={phoneError ? true : undefined}
            aria-describedby={phoneError ? "phone-hint phone-error" : "phone-hint"}
            onChange={(e) => setPhone(groupDigits(e.target.value))}
            className="rounded-l-none"
          />
        </div>
        <p id="phone-hint" className="text-xs text-muted-foreground">
          {t("phoneHint")}
        </p>
        {phoneError ? (
          <p id="phone-error" role="alert" className="text-sm text-destructive">
            {phoneError}
          </p>
        ) : null}
      </div>
      <Turnstile ref={turnstile} onToken={setBotToken} locale={locale} />
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
  disabled,
  onResend,
}: {
  resendAt: number;
  disabled: boolean;
  onResend: () => void;
}) {
  const t = useTranslations("web.auth");
  const [now, setNow] = useState(() => Date.now());

  useEffect(() => {
    if (resendAt <= now) return;
    const timer = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(timer);
  }, [resendAt, now]);

  const seconds = Math.max(0, Math.ceil((resendAt - now) / 1000));

  // No live region on the countdown (it would be read out every second); the button appearing is enough.
  return seconds > 0 ? (
    <span className="text-muted-foreground tabular-nums">{t("resendIn", { seconds })}</span>
  ) : (
    <Button variant="link" type="button" className="h-auto p-0" disabled={disabled} onClick={onResend}>
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
