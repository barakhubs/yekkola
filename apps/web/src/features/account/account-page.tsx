"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { ApiError, success } from "@yekkola/api-client";
import {
  getMeExportShowQueryKey,
  meExportStore,
  useMeExportShow,
} from "@yekkola/api-client/endpoints/data-export";
import {
  getMeDevicesIndexQueryKey,
  meDevicesDestroy,
  useMeDevicesIndex,
} from "@yekkola/api-client/endpoints/device";
import { mePhoneOtp, mePhoneUpdate } from "@yekkola/api-client/endpoints/phone";
import { meDeletionCancel, meDestroy, meUpdate } from "@yekkola/api-client/endpoints/profile";
import { useProvincesIndex } from "@yekkola/api-client/endpoints/province";
import type { UpdateProfileRequestLocale, UserResource } from "@yekkola/api-client/model";
import { formatDate, formatDateTime, formatPhone, type Locale } from "@yekkola/i18n";
import { ConfirmDialog } from "@yekkola/ui/blocks/confirm-dialog";
import { PageHeader } from "@yekkola/ui/blocks/page-header";
import { Button } from "@yekkola/ui/components/button";
import { Input } from "@yekkola/ui/components/input";
import { Label } from "@yekkola/ui/components/label";
import { Smartphone } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { type FormEvent, type ReactNode, useState } from "react";

import { useSession } from "@/features/auth/hooks/use-session";
import { fieldErrorsOf, useErrorText } from "@/features/studio/hooks";
import { selectClass } from "@/features/studio/styles";
import { usePathname, useRouter } from "@/i18n/navigation";
import { setResource } from "@/lib/query-cache";

/** Account settings (PRD-01 FR-04, FR-07–FR-11): profile, phone number, devices, data export, deletion. */
export function AccountPage() {
  const t = useTranslations("web.account");
  const { user } = useSession();

  if (!user) return null;

  return (
    <div className="mx-auto grid max-w-3xl gap-6 px-4 py-8">
      <PageHeader title={t("title")} description={t("intro")} />
      <ProfileSection key={user.id} user={user} />
      <PhoneSection user={user} />
      <DevicesSection />
      <ExportSection />
      <DeletionSection user={user} />
    </div>
  );
}

function Section({
  title,
  description,
  children,
}: {
  title: string;
  description?: string;
  children: ReactNode;
}) {
  return (
    <section className="grid gap-4 rounded-xl border bg-background p-4 sm:p-6">
      <div className="grid gap-1">
        <h2 className="text-lg font-bold">{title}</h2>
        {description ? <p className="text-sm text-muted-foreground">{description}</p> : null}
      </div>
      {children}
    </section>
  );
}

function Field({
  id,
  label,
  error,
  children,
}: {
  id: string;
  label: string;
  error?: string;
  children: ReactNode;
}) {
  return (
    <div className="grid gap-2">
      <Label htmlFor={id}>{label}</Label>
      {children}
      {error ? <p className="text-sm text-destructive">{error}</p> : null}
    </div>
  );
}

function ErrorLine({ error }: { error: unknown }) {
  const errorText = useErrorText();

  return (
    <p role="alert" className="text-sm text-destructive">
      {errorText(error)}
    </p>
  );
}

/** Keeps the cached session in step with what the API answered. */
function useStoreUser() {
  const queryClient = useQueryClient();
  const { queryKey } = useSession();

  return (user: UserResource) => setResource(queryClient, queryKey, user);
}

function ProfileSection({ user }: { user: UserResource }) {
  const t = useTranslations("web.account");
  const store = useStoreUser();
  const router = useRouter();
  const pathname = usePathname();
  const provinces = useProvincesIndex({ query: { select: (r) => success(r).data, staleTime: 60 * 60_000 } });
  const [name, setName] = useState(user.name ?? "");
  const [email, setEmail] = useState(user.email ?? "");
  const [locale, setLocale] = useState(user.locale as UpdateProfileRequestLocale);
  const [provinceId, setProvinceId] = useState(user.province?.id ?? "");
  const [city, setCity] = useState(user.city ?? "");

  const save = useMutation({
    mutationFn: async () =>
      success(
        await meUpdate({
          name: name.trim(),
          email: email.trim() || null,
          locale,
          province_id: provinceId || null,
          city: city.trim() || null,
        }),
      ).data as UserResource,
    onSuccess: (saved) => {
      store(saved);
      if (saved.locale !== user.locale) router.replace(pathname, { locale: saved.locale as Locale });
    },
  });
  const errors = fieldErrorsOf(save.error);

  return (
    <Section title={t("profile")}>
      <form
        noValidate
        className="grid gap-4"
        onSubmit={(e: FormEvent) => {
          e.preventDefault();
          if (!save.isPending) save.mutate();
        }}
      >
        <Field id="account-name" label={t("fields.name")} error={errors.name}>
          <Input
            id="account-name"
            autoComplete="name"
            value={name}
            onChange={(e) => setName(e.target.value)}
          />
        </Field>
        <Field id="account-email" label={t("fields.email")} error={errors.email}>
          <Input
            id="account-email"
            type="email"
            autoComplete="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
          />
        </Field>
        <Field id="account-locale" label={t("fields.locale")} error={errors.locale}>
          <select
            id="account-locale"
            className={selectClass}
            value={locale}
            onChange={(e) => setLocale(e.target.value as UpdateProfileRequestLocale)}
          >
            <option value="fr">Français</option>
            <option value="en">English</option>
          </select>
        </Field>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field id="account-province" label={t("fields.province")} error={errors.province_id}>
            <select
              id="account-province"
              className={selectClass}
              value={provinceId}
              onChange={(e) => setProvinceId(e.target.value)}
            >
              <option value="">{t("provinceNone")}</option>
              {(provinces.data ?? []).map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </Field>
          <Field id="account-city" label={t("fields.city")} error={errors.city}>
            <Input id="account-city" value={city} onChange={(e) => setCity(e.target.value)} />
          </Field>
        </div>
        <div className="flex flex-wrap items-center gap-3">
          <Button type="submit" disabled={save.isPending} aria-busy={save.isPending}>
            {t("save")}
          </Button>
          {save.isSuccess ? <p className="text-sm text-muted-foreground">{t("saved")}</p> : null}
          {save.isError && Object.keys(errors).length === 0 ? <ErrorLine error={save.error} /> : null}
        </div>
      </form>
    </Section>
  );
}

/** New number confirmed by a code sent to it; needs a recent sign-in, and signs out every other session (FR-08). */
function PhoneSection({ user }: { user: UserResource }) {
  const t = useTranslations("web.account");
  const store = useStoreUser();
  const [editing, setEditing] = useState(false);
  const [phone, setPhone] = useState("");
  const [sentTo, setSentTo] = useState<string | null>(null);
  const [code, setCode] = useState("");

  const requestCode = useMutation({
    mutationFn: (to: string) => mePhoneOtp({ phone: to }),
    onSuccess: (_, to) => setSentTo(to),
  });
  const confirm = useMutation({
    mutationFn: async () => success(await mePhoneUpdate({ phone: sentTo ?? "", code })).data as UserResource,
    onSuccess: (saved) => {
      store(saved);
      setEditing(false);
      setSentTo(null);
      setPhone("");
      setCode("");
    },
  });
  const error = requestCode.error ?? confirm.error;
  const errors = fieldErrorsOf(error);
  const needsReauth = error instanceof ApiError && error.code === "auth.reauthentication_required";

  return (
    <Section title={t("phone")} description={t("phoneIntro")}>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <span className="font-semibold">{formatPhone(user.phone)}</span>
        {editing ? null : (
          <Button variant="outline" size="sm" onClick={() => setEditing(true)}>
            {t("change")}
          </Button>
        )}
      </div>
      {editing ? (
        <form
          noValidate
          className="grid gap-4"
          onSubmit={(e: FormEvent) => {
            e.preventDefault();
            if (!sentTo) requestCode.mutate(phone.replace(/\s/g, ""));
            else if (!confirm.isPending) confirm.mutate();
          }}
        >
          <Field id="account-phone" label={t("fields.newPhone")} error={errors.phone}>
            <Input
              id="account-phone"
              type="tel"
              inputMode="tel"
              autoComplete="tel"
              disabled={sentTo !== null}
              value={phone}
              onChange={(e) => setPhone(e.target.value)}
            />
          </Field>
          {sentTo ? (
            <Field id="account-code" label={t("fields.code")} error={errors.code}>
              <p className="text-xs text-muted-foreground">{t("codeSent", { phone: formatPhone(sentTo) })}</p>
              <Input
                id="account-code"
                inputMode="numeric"
                autoComplete="one-time-code"
                maxLength={6}
                className="max-w-40 tracking-[0.3em]"
                value={code}
                onChange={(e) => setCode(e.target.value.replace(/\D/g, ""))}
              />
            </Field>
          ) : null}
          <p className="text-xs text-muted-foreground">{t("phoneNotice")}</p>
          <div className="flex flex-wrap items-center gap-3">
            <Button
              type="submit"
              disabled={
                (sentTo ? code.length !== 6 : !phone.trim()) || requestCode.isPending || confirm.isPending
              }
            >
              {sentTo ? t("confirm") : t("sendCode")}
            </Button>
            <Button
              type="button"
              variant="ghost"
              onClick={() => {
                setEditing(false);
                setSentTo(null);
                setCode("");
                requestCode.reset();
                confirm.reset();
              }}
            >
              {t("cancel")}
            </Button>
          </div>
          {needsReauth ? (
            <p role="alert" className="text-sm text-destructive">
              {t("reauth")}
            </p>
          ) : error && Object.keys(errors).length === 0 ? (
            <ErrorLine error={error} />
          ) : null}
        </form>
      ) : null}
    </Section>
  );
}

/** Registered mobile devices (they hold downloads); removing one signs it out (PRD-07). */
function DevicesSection() {
  const t = useTranslations("web.account");
  const locale = useLocale() as Locale;
  const queryClient = useQueryClient();
  const devices = useMeDevicesIndex({ query: { select: (r) => success(r).data } });
  const [removeError, setRemoveError] = useState<unknown>(null);

  return (
    <Section title={t("devices")} description={t("devicesIntro")}>
      {devices.isError ? <ErrorLine error={devices.error} /> : null}
      {devices.data?.length === 0 ? <p className="text-sm text-muted-foreground">{t("noDevices")}</p> : null}
      <ul className="grid gap-3">
        {(devices.data ?? []).map((device) => (
          <li key={device.id} className="flex flex-wrap items-center justify-between gap-3">
            <span className="flex items-start gap-3">
              <Smartphone aria-hidden className="mt-0.5 size-5 text-primary" />
              <span className="grid">
                <span className="font-semibold">
                  {device.name ?? t(`platforms.${device.platform as "android" | "ios"}`)}
                  {device.is_current ? ` · ${t("thisDevice")}` : ""}
                </span>
                <span className="text-sm text-muted-foreground">
                  {device.last_active_at
                    ? t("lastActive", { date: formatDateTime(device.last_active_at, locale) })
                    : t("registered", { date: formatDate(device.registered_at, locale) })}
                </span>
              </span>
            </span>
            <ConfirmDialog
              trigger={
                <Button variant="outline" size="sm">
                  {t("remove")}
                </Button>
              }
              title={t("removeTitle")}
              description={t("removeBody")}
              confirmLabel={t("remove")}
              cancelLabel={t("cancel")}
              destructive
              error={removeError ? <ErrorText error={removeError} /> : undefined}
              onOpenChange={() => setRemoveError(null)}
              onConfirm={async () => {
                try {
                  await meDevicesDestroy(device.id);
                } catch (e) {
                  setRemoveError(e);
                  throw e;
                }
                await queryClient.invalidateQueries({ queryKey: getMeDevicesIndexQueryKey() });
              }}
            />
          </li>
        ))}
      </ul>
    </Section>
  );
}

function ErrorText({ error }: { error: unknown }) {
  return <>{useErrorText()(error)}</>;
}

/** A copy of the account's data, built in the background, downloadable for a few days (FR-10). */
function ExportSection() {
  const t = useTranslations("web.account");
  const locale = useLocale() as Locale;
  const queryClient = useQueryClient();
  const latest = useMeExportShow({
    query: {
      select: (r) => success(r).data,
      retry: (failures, error) => !(error instanceof ApiError && error.status < 500) && failures < 2,
      // Poll while it is being built.
      refetchInterval: (query) =>
        query.state.data && success(query.state.data).data.status === "pending" ? 5000 : false,
    },
  });
  const request = useMutation({
    mutationFn: () => meExportStore(),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: getMeExportShowQueryKey() }),
  });
  const current = latest.data;
  const pending = current?.status === "pending";

  return (
    <Section title={t("export")} description={t("exportIntro")}>
      {current ? (
        <p className="text-sm">
          {pending
            ? t("exportPending")
            : current.status === "failed"
              ? t("exportFailed")
              : current.download_url && current.expires_at
                ? t("exportReady", { date: formatDateTime(current.expires_at, locale) })
                : t("exportExpired")}
        </p>
      ) : null}
      <div className="flex flex-wrap items-center gap-3">
        {current?.download_url ? (
          <Button asChild>
            <a href={current.download_url}>{t("download")}</a>
          </Button>
        ) : null}
        <Button
          variant={current?.download_url ? "outline" : "default"}
          disabled={pending || request.isPending}
          aria-busy={request.isPending}
          onClick={() => request.mutate()}
        >
          {t("requestExport")}
        </Button>
      </div>
      {request.isError ? <ErrorLine error={request.error} /> : null}
    </Section>
  );
}

/** Deletion after a grace period, cancellable until then (FR-11). */
function DeletionSection({ user }: { user: UserResource }) {
  const t = useTranslations("web.account");
  const locale = useLocale() as Locale;
  const store = useStoreUser();
  const [error, setError] = useState<unknown>(null);
  const cancel = useMutation({
    mutationFn: async () => success(await meDeletionCancel()).data as UserResource,
    onSuccess: store,
  });

  if (user.deletion_scheduled_for) {
    return (
      <Section title={t("deletion")}>
        <p className="text-sm">
          {t("deletionScheduled", { date: formatDate(user.deletion_scheduled_for, locale) })}
        </p>
        <Button className="w-fit" disabled={cancel.isPending} onClick={() => cancel.mutate()}>
          {t("keepAccount")}
        </Button>
        {cancel.isError ? <ErrorLine error={cancel.error} /> : null}
      </Section>
    );
  }

  return (
    <Section title={t("deletion")} description={t("deletionIntro")}>
      <ConfirmDialog
        trigger={
          <Button variant="destructive" className="w-fit">
            {t("deleteAccount")}
          </Button>
        }
        title={t("deleteTitle")}
        description={t("deleteBody")}
        confirmLabel={t("deleteAccount")}
        cancelLabel={t("cancel")}
        destructive
        error={error ? <ErrorText error={error} /> : undefined}
        onOpenChange={() => setError(null)}
        onConfirm={async () => {
          try {
            store(success(await meDestroy()).data as UserResource);
          } catch (e) {
            setError(e);
            throw e;
          }
        }}
      />
    </Section>
  );
}
