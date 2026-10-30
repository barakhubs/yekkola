"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { success } from "@yekkola/api-client";
import {
  adminUsersDevicesDestroy,
  adminUsersSignOut,
  adminUsersStatus,
  getAdminUsersIndexQueryKey,
  getAdminUsersShowQueryKey,
  useAdminUsersShow,
} from "@yekkola/api-client/endpoints/user";
import type { AdminUserDetailResource, UserStatus } from "@yekkola/api-client/model";
import { formatDateTime, formatMoney, type Locale } from "@yekkola/i18n";
import { ConfirmDialog } from "@yekkola/ui/blocks/confirm-dialog";
import { EmptyState } from "@yekkola/ui/blocks/empty-state";
import { StatusBadge } from "@yekkola/ui/blocks/status-badge";
import { Button } from "@yekkola/ui/components/button";
import { Skeleton } from "@yekkola/ui/components/skeleton";
import { ArrowLeft } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import type { ReactNode } from "react";

import { useErrorText } from "@/features/common/errors";
import { Link } from "@/i18n/navigation";

import { USER_TONES, userStateOf } from "./status";

type Action = { kind: "status"; status: UserStatus } | { kind: "signOut" } | { kind: "device"; id: string };

/**
 * One account (PRD-09 FR-04): profile, devices, recent enrollments and orders; suspend, ban or reactivate (reason
 * required, kept in the audit log), sign out everywhere, remove a device. Only the actions the API allows show.
 */
export function UserDetail({ id }: { id: string }) {
  const t = useTranslations("admin.users");
  const common = useTranslations("common");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const queryClient = useQueryClient();
  const user = useAdminUsersShow(id, { query: { select: (r) => success(r).data } });

  const act = useMutation({
    mutationFn: async ({ action, note }: { action: Action; note: string }) => {
      const response =
        action.kind === "status"
          ? await adminUsersStatus(id, { status: action.status, note })
          : action.kind === "signOut"
            ? await adminUsersSignOut(id)
            : await adminUsersDevicesDestroy(id, action.id);
      return success(response).data as AdminUserDetailResource;
    },
    onSuccess: (data) =>
      queryClient.setQueryData(getAdminUsersShowQueryKey(id), {
        data: { data },
        status: 200,
        headers: new Headers(),
      }),
    onSettled: () => queryClient.invalidateQueries({ queryKey: getAdminUsersIndexQueryKey() }),
  });
  const errorFor = (match: (a: Action) => boolean) =>
    act.isError && act.variables && match(act.variables.action) ? errorText(act.error) : undefined;
  const resetOnOpen = (open: boolean) => {
    if (open) act.reset();
  };

  if (user.isPending) {
    return (
      <div className="mx-auto grid max-w-5xl gap-4 px-4 py-8 sm:px-6">
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }
  if (user.isError) {
    return (
      <div className="mx-auto max-w-xl px-4 py-16">
        <EmptyState title={errorText(user.error)} />
      </div>
    );
  }

  const u = user.data;
  const state = userStateOf(u.status);
  const statusAction = (status: UserStatus, label: string, hint: string, destructive = false) => (
    <ConfirmDialog
      trigger={<Button variant={destructive ? "destructive" : "outline"}>{label}</Button>}
      title={t("confirmTitle", { action: label, name: u.name ?? "—" })}
      description={hint}
      reason={{ label: t("reason"), minLength: 3 }}
      confirmLabel={label}
      cancelLabel={common("cancel")}
      destructive={destructive}
      onConfirm={async (note) => {
        await act.mutateAsync({ action: { kind: "status", status }, note });
      }}
      error={errorFor((a) => a.kind === "status" && a.status === status)}
      onOpenChange={resetOnOpen}
    />
  );

  return (
    <div className="mx-auto grid max-w-5xl gap-6 px-4 py-8 sm:px-6">
      <Link
        href="/users"
        className="flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
      >
        <ArrowLeft aria-hidden className="size-4" />
        {t("back")}
      </Link>

      <header className="flex flex-wrap items-start justify-between gap-4">
        <div className="grid gap-2">
          <h1 className="text-2xl font-extrabold">{u.name ?? t("noName")}</h1>
          <span className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
            <StatusBadge status={state} tones={USER_TONES} label={t(`status.${state}`)} />
            {u.roles.map((r) => t(`roles.${r}` as never)).join(", ")}
          </span>
        </div>
        <div className="flex flex-wrap gap-2">
          {u.can.reactivate ? statusAction("active", t("reactivate"), t("reactivateHint")) : null}
          {u.can.suspend ? statusAction("suspended", t("suspend"), t("suspendHint")) : null}
          {u.can.ban ? statusAction("banned", t("ban"), t("banHint"), true) : null}
          {u.can.sign_out ? (
            <ConfirmDialog
              trigger={<Button variant="outline">{t("signOut")}</Button>}
              title={t("signOutConfirm")}
              description={t("signOutHint")}
              confirmLabel={t("signOut")}
              cancelLabel={common("cancel")}
              onConfirm={async () => {
                await act.mutateAsync({ action: { kind: "signOut" }, note: "" });
              }}
              error={errorFor((a) => a.kind === "signOut")}
              onOpenChange={resetOnOpen}
            />
          ) : null}
        </div>
      </header>

      <section className="grid gap-3 rounded-xl border p-4 sm:grid-cols-3">
        <Fact label={t("columns.phone")}>{u.phone ?? "—"}</Fact>
        <Fact label={t("email")}>{u.email ?? "—"}</Fact>
        <Fact label={t("place")}>{[u.city, u.province].filter(Boolean).join(", ") || "—"}</Fact>
        <Fact label={t("columns.created")}>{formatDateTime(u.created_at, locale)}</Fact>
        <Fact label={t("lastLogin")}>{u.last_login_at ? formatDateTime(u.last_login_at, locale) : "—"}</Fact>
        <Fact label={t("columns.lastSeen")}>
          {u.last_seen_at ? formatDateTime(u.last_seen_at, locale) : "—"}
        </Fact>
        {u.deletion_requested_at ? (
          <Fact label={t("deletionRequested")}>{formatDateTime(u.deletion_requested_at, locale)}</Fact>
        ) : null}
      </section>

      <Section title={t("devices")} empty={u.devices.length === 0 ? t("noDevices") : null}>
        {u.devices.map((d) => (
          <li
            key={d.id}
            className="flex flex-wrap items-center justify-between gap-2 rounded-md border px-3 py-2"
          >
            <span>
              {d.name ?? d.platform} · {d.app_version ?? "—"} ·{" "}
              {d.last_active_at
                ? formatDateTime(d.last_active_at, locale)
                : formatDateTime(d.registered_at, locale)}
            </span>
            {u.can.remove_device ? (
              <ConfirmDialog
                trigger={
                  <Button size="sm" variant="ghost">
                    {t("removeDevice")}
                  </Button>
                }
                title={t("removeDeviceConfirm")}
                description={t("removeDeviceHint")}
                confirmLabel={t("removeDevice")}
                cancelLabel={common("cancel")}
                destructive
                onConfirm={async () => {
                  await act.mutateAsync({ action: { kind: "device", id: d.id }, note: "" });
                }}
                error={errorFor((a) => a.kind === "device" && a.id === d.id)}
                onOpenChange={resetOnOpen}
              />
            ) : null}
          </li>
        ))}
      </Section>

      <Section
        title={t("enrollments", { count: u.enrollment_count })}
        empty={u.enrollments.length === 0 ? t("noEnrollments") : null}
      >
        {u.enrollments.map((e) => (
          <li key={e.course_id} className="flex flex-wrap justify-between gap-2 rounded-md border px-3 py-2">
            <span>{e.course ?? "—"}</span>
            <span className="text-muted-foreground">
              {t(`sources.${e.source}` as never)} · {t(`access.${e.status}` as never)} · {e.progress_pct} %
            </span>
          </li>
        ))}
      </Section>

      <Section title={t("orders")} empty={u.orders.length === 0 ? t("noOrders") : null}>
        {u.orders.map((o) => (
          <li key={o.id} className="flex flex-wrap justify-between gap-2 rounded-md border px-3 py-2">
            <Link href={`/orders/${o.id}`} className="font-semibold text-primary hover:underline">
              {o.number}
            </Link>
            <span className="text-muted-foreground">
              {formatDateTime(o.created_at, locale)} · {formatMoney(o.total, locale)} · {o.status}
            </span>
          </li>
        ))}
      </Section>
    </div>
  );
}

function Section({ title, empty, children }: { title: string; empty: string | null; children: ReactNode }) {
  return (
    <section className="grid gap-2">
      <h2 className="font-bold">{title}</h2>
      {empty ? (
        <p className="text-sm text-muted-foreground">{empty}</p>
      ) : (
        <ul className="grid gap-1 text-sm">{children}</ul>
      )}
    </section>
  );
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="grid gap-0.5">
      <span className="text-xs font-semibold text-muted-foreground">{label}</span>
      <span className="text-sm">{children}</span>
    </div>
  );
}
