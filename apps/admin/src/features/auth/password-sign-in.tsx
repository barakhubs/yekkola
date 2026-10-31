"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { ApiError, success } from "@yekkola/api-client";
import { getMeShowQueryKey } from "@yekkola/api-client/endpoints/profile";
import { authStaffSignIn, mePassword } from "@yekkola/api-client/endpoints/staff-password";
import type { UserResource } from "@yekkola/api-client/model";
import { Button } from "@yekkola/ui/components/button";
import { Input } from "@yekkola/ui/components/input";
import { Label } from "@yekkola/ui/components/label";
import { useLocale, useTranslations } from "next-intl";
import { useSearchParams } from "next/navigation";
import { type FormEvent, useRef, useState } from "react";

import { useErrorText } from "@/features/common/errors";
import { useRouter } from "@/i18n/navigation";

import { safeNext } from "./safe-next";
import { useSession } from "./session";
import { Turnstile, type TurnstileHandle } from "./turnstile";

/** Staff sign-in with email + password (owner decision, 2026-10-31); the API answers every failure the same way. */
export function PasswordSignIn() {
  const t = useTranslations("admin.auth");
  const locale = useLocale();
  const errorText = useErrorText();
  const router = useRouter();
  const next = safeNext(useSearchParams().get("next"));
  const queryClient = useQueryClient();
  const turnstile = useRef<TurnstileHandle>(null);
  const [botToken, setBotToken] = useState<string | null>(null);
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");

  const signIn = useMutation({
    mutationFn: async () =>
      success(await authStaffSignIn({ email: email.trim(), password, bot_token: botToken }))
        .data as UserResource,
    onSuccess: async (user) => {
      queryClient.setQueryData(getMeShowQueryKey(), {
        data: { data: user },
        status: 200,
        headers: new Headers(),
      });
      await queryClient.invalidateQueries();
      router.replace(next);
    },
    // Bot-check tokens are single use.
    onSettled: () => turnstile.current?.reset(),
  });

  return (
    <form
      noValidate
      className="grid gap-4"
      onSubmit={(e: FormEvent) => {
        e.preventDefault();
        if (botToken && email.trim() && password && !signIn.isPending) signIn.mutate();
      }}
    >
      <div className="grid gap-2">
        <Label htmlFor="staff-email">{t("email")}</Label>
        <Input
          id="staff-email"
          type="email"
          autoComplete="username"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
        />
      </div>
      <div className="grid gap-2">
        <Label htmlFor="staff-password">{t("password")}</Label>
        <Input
          id="staff-password"
          type="password"
          autoComplete="current-password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
        />
      </div>
      <Turnstile ref={turnstile} onToken={setBotToken} locale={locale} />
      {signIn.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(signIn.error)}
        </p>
      ) : null}
      <Button
        type="submit"
        disabled={!botToken || !email.trim() || !password || signIn.isPending}
        aria-busy={signIn.isPending}
      >
        {t("signInWithPassword")}
      </Button>
    </form>
  );
}

/**
 * Choose a new password: required after a password was set for you (first sign-in, or reset by a staff manager),
 * and available to any staff member. The API asks for the current one when there is one.
 */
export function ChangePassword({ required }: { required: boolean }) {
  const t = useTranslations("admin.auth");
  const errorText = useErrorText();
  const queryClient = useQueryClient();
  const session = useSession();
  const [current, setCurrent] = useState("");
  const [password, setPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");

  const change = useMutation({
    mutationFn: async () =>
      success(
        await mePassword({
          current_password: current || null,
          password,
          password_confirmation: confirmation,
        } as Parameters<typeof mePassword>[0]),
      ).data as UserResource,
    onSuccess: async (user) => {
      queryClient.setQueryData(getMeShowQueryKey(), {
        data: { data: user },
        status: 200,
        headers: new Headers(),
      });
      await queryClient.invalidateQueries();
      setCurrent("");
      setPassword("");
      setConfirmation("");
    },
  });
  const fields = change.error instanceof ApiError ? change.error.fieldErrors() : {};

  return (
    <form
      noValidate
      className="grid max-w-md gap-4"
      onSubmit={(e: FormEvent) => {
        e.preventDefault();
        if (!change.isPending) change.mutate();
      }}
    >
      <div className="grid gap-1">
        <h1 className="text-xl font-bold">{t("changeTitle")}</h1>
        <p className="text-sm text-muted-foreground">{required ? t("changeRequired") : t("changeHint")}</p>
      </div>
      {session.user?.has_password ? (
        <div className="grid gap-2">
          <Label htmlFor="pw-current">{t("currentPassword")}</Label>
          <Input
            id="pw-current"
            type="password"
            autoComplete="current-password"
            value={current}
            onChange={(e) => setCurrent(e.target.value)}
          />
        </div>
      ) : null}
      <div className="grid gap-2">
        <Label htmlFor="pw-new">{t("newPassword")}</Label>
        <Input
          id="pw-new"
          type="password"
          autoComplete="new-password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
        />
        <p className="text-xs text-muted-foreground">{t("passwordRule")}</p>
        {fields.password ? <p className="text-sm text-destructive">{fields.password}</p> : null}
      </div>
      <div className="grid gap-2">
        <Label htmlFor="pw-confirm">{t("confirmPassword")}</Label>
        <Input
          id="pw-confirm"
          type="password"
          autoComplete="new-password"
          value={confirmation}
          onChange={(e) => setConfirmation(e.target.value)}
        />
      </div>
      {change.isError && !fields.password ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(change.error)}
        </p>
      ) : null}
      {change.isSuccess ? <p className="text-sm text-muted-foreground">{t("passwordSaved")}</p> : null}
      <Button
        type="submit"
        className="w-fit"
        disabled={change.isPending || !password || !confirmation}
        aria-busy={change.isPending}
      >
        {t("savePassword")}
      </Button>
    </form>
  );
}
