"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { ApiError, success } from "@yekkola/api-client";
import { authOtpRequest, authOtpVerify } from "@yekkola/api-client/endpoints/otp";
import { getMeShowQueryKey } from "@yekkola/api-client/endpoints/profile";
import type { UserResource } from "@yekkola/api-client/model";
import { formatPhone } from "@yekkola/i18n";
import { Button } from "@yekkola/ui/components/button";
import { Input } from "@yekkola/ui/components/input";
import { Label } from "@yekkola/ui/components/label";
import { useLocale, useTranslations } from "next-intl";
import { useSearchParams } from "next/navigation";
import { type FormEvent, useEffect, useRef, useState } from "react";

import { useRouter } from "@/i18n/navigation";

import { useSession } from "./session";
import { Turnstile, type TurnstileHandle } from "./turnstile";

/** Codes after which this challenge can't succeed: offer a new code straight away. */
const DEAD_CHALLENGE = new Set(["otp.expired", "otp.too_many_attempts"]);

/** Same-site paths only, never back to the sign-in page itself. */
function safeNext(next: string | null): string {
  return next && next.startsWith("/") && !next.startsWith("//") && !next.startsWith("/login") ? next : "/";
}

function displayPhone(raw: string): string {
  const digits = raw.replace(/\D/g, "");
  if (raw.trim().startsWith("+") || digits.startsWith("243")) return formatPhone(`+${digits}`);
  return formatPhone(`+243${digits.startsWith("0") ? digits.slice(1) : digits}`);
}

/**
 * Staff sign-in (PRD-09 FR-01): the same phone + SMS code as everyone (PRD-01), then back to `?next=`. Whether the
 * account may use the back office is the API's call; the console explains a refusal.
 */
export function SignInForm() {
  const t = useTranslations("admin.auth");
  const errors = useTranslations("errors");
  const locale = useLocale();
  const router = useRouter();
  const next = safeNext(useSearchParams().get("next"));
  const queryClient = useQueryClient();
  const session = useSession();
  const turnstile = useRef<TurnstileHandle>(null);
  const codeInput = useRef<HTMLInputElement>(null);
  const [phone, setPhone] = useState("");
  const [sentTo, setSentTo] = useState<string | null>(null);
  const [code, setCode] = useState("");
  const [botToken, setBotToken] = useState<string | null>(null);

  // Already signed in (back button, second tab): go on.
  useEffect(() => {
    if (session.user) router.replace(next);
  }, [session.user, next, router]);

  const errorMessage = (error: unknown) => {
    if (error instanceof ApiError) {
      const fields = error.fieldErrors();
      return fields.phone ?? fields.code ?? error.title ?? errors("generic");
    }
    return errors("network");
  };

  const requestCode = useMutation({
    mutationFn: async (to: string) =>
      success(await authOtpRequest({ phone: to, bot_token: botToken ?? undefined })),
    onSuccess: (_, to) => {
      setSentTo(to);
      requestAnimationFrame(() => codeInput.current?.focus());
    },
    // Bot-check tokens are single-use: get a new one for the next request.
    onSettled: () => turnstile.current?.reset(),
  });

  const verify = useMutation({
    mutationFn: async ({ to, digits }: { to: string; digits: string }) =>
      success(await authOtpVerify({ phone: to, code: digits })).data,
    onSuccess: async (result) => {
      const user = result.user as UserResource;
      queryClient.setQueryData(getMeShowQueryKey(), {
        data: { data: user },
        status: 200,
        headers: new Headers(),
      });
      await queryClient.invalidateQueries();
      router.replace(next);
    },
    onError: (error) => {
      setCode("");
      if (error instanceof ApiError && DEAD_CHALLENGE.has(error.code ?? "")) setSentTo(null);
      requestAnimationFrame(() => codeInput.current?.focus());
    },
  });

  if (sentTo) {
    const submit = (digits: string) => {
      if (digits.length === 6 && !verify.isPending) verify.mutate({ to: sentTo, digits });
    };

    return (
      <form
        className="grid gap-4"
        onSubmit={(e: FormEvent) => {
          e.preventDefault();
          submit(code);
        }}
      >
        <p className="text-sm text-muted-foreground">{t("codeSentTo", { phone: displayPhone(sentTo) })}</p>
        <div className="grid gap-2">
          <Label htmlFor="otp-code">{t("codeLabel")}</Label>
          <Input
            id="otp-code"
            ref={codeInput}
            inputMode="numeric"
            autoComplete="one-time-code"
            pattern="[0-9]{6}"
            maxLength={6}
            value={code}
            aria-invalid={verify.isError || undefined}
            aria-describedby={verify.isError ? "otp-error" : undefined}
            onChange={(e) => {
              const digits = e.target.value.replace(/\D/g, "").slice(0, 6);
              setCode(digits);
              submit(digits);
            }}
            className="h-12 text-center text-2xl tracking-[0.5em] tabular-nums"
          />
          {verify.isError ? (
            <p id="otp-error" role="alert" className="text-sm text-destructive">
              {errorMessage(verify.error)}
            </p>
          ) : null}
        </div>
        <Button type="submit" disabled={code.length !== 6 || verify.isPending} aria-busy={verify.isPending}>
          {t("verify")}
        </Button>
        <Button
          type="button"
          variant="link"
          className="h-auto justify-self-start p-0"
          onClick={() => {
            verify.reset();
            setCode("");
            setSentTo(null);
          }}
        >
          {t("changeNumber")}
        </Button>
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
        <Input
          id="phone"
          type="tel"
          inputMode="tel"
          autoComplete="tel"
          required
          value={phone}
          aria-invalid={phoneError ? true : undefined}
          aria-describedby={phoneError ? "phone-hint phone-error" : "phone-hint"}
          onChange={(e) => setPhone(e.target.value)}
        />
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
      <Button type="submit" disabled={!botToken || requestCode.isPending} aria-busy={requestCode.isPending}>
        {t("sendCode")}
      </Button>
    </form>
  );
}
