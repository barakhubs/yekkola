"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { success } from "@yekkola/api-client";
import {
  getMeNotificationPreferencesShowQueryKey,
  getMeNotificationsIndexQueryKey,
  getMeNotificationsUnreadCountQueryKey,
  meNotificationPreferencesUpdate,
  meNotificationsRead,
  useMeNotificationPreferencesShow,
  useMeNotificationsIndex,
} from "@yekkola/api-client/endpoints/notification";
import type {
  NotificationPreferencesRequestPreferencesItem,
  NotificationResource,
} from "@yekkola/api-client/model";
import { formatDateTime, type Locale } from "@yekkola/i18n";
import { Button } from "@yekkola/ui/components/button";
import { cn } from "@yekkola/ui/lib/utils";
import { useLocale, useTranslations } from "next-intl";
import { useState } from "react";

import { useErrorText } from "@/features/studio/hooks";
import { useRouter } from "@/i18n/navigation";

type Preferences = { type: string; channels: Record<string, boolean> }[];

/**
 * Notification centre (PRD-10 FR-03, FR-05): newest first, unread marked; opening one marks it read and follows its
 * link. Below, the events I may turn off, per channel. Which events can be turned off is the API's decision.
 */
export function NotificationsPage() {
  const t = useTranslations("web.notifications");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const router = useRouter();
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const list = useMeNotificationsIndex(
    { page, per_page: 20 },
    { query: { select: (r) => success(r), placeholderData: (previous) => previous } },
  );
  const refresh = () =>
    Promise.all([
      queryClient.invalidateQueries({ queryKey: getMeNotificationsIndexQueryKey() }),
      queryClient.invalidateQueries({ queryKey: getMeNotificationsUnreadCountQueryKey() }),
    ]);
  const read = useMutation({
    mutationFn: async (ids?: string[]) => success(await meNotificationsRead(ids ? { ids } : {})),
    onSuccess: refresh,
  });
  const lastPage = list.data?.meta.last_page ?? 1;
  const hasUnread = (list.data?.data ?? []).some((n) => n.read_at === null);

  const open = (n: NotificationResource) => {
    if (n.read_at === null) read.mutate([n.id]);
    if (n.link) router.push(n.link);
  };

  return (
    <div className="mx-auto grid max-w-3xl gap-8 px-4 py-8">
      <section aria-labelledby="notifications-title" className="grid gap-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <h1 id="notifications-title" className="text-2xl font-extrabold">
            {t("title")}
          </h1>
          {hasUnread ? (
            <Button
              variant="outline"
              size="sm"
              disabled={read.isPending}
              onClick={() => read.mutate(undefined)}
            >
              {t("markAllRead")}
            </Button>
          ) : null}
        </div>
        {list.isError ? (
          <p role="alert" className="text-sm text-destructive">
            {errorText(list.error)}
          </p>
        ) : null}
        {list.data?.data.length === 0 ? <p className="text-muted-foreground">{t("empty")}</p> : null}
        <ul className="grid gap-2" aria-busy={list.isFetching}>
          {(list.data?.data ?? []).map((n) => (
            <li key={n.id}>
              <button
                type="button"
                onClick={() => open(n)}
                className={cn(
                  "grid w-full gap-1 rounded-lg border p-3 text-left hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none",
                  n.read_at === null && "border-primary/40 bg-accent/40",
                )}
              >
                <span className="flex items-center gap-2 font-semibold">
                  {n.read_at === null ? (
                    <span className="size-2 shrink-0 rounded-full bg-primary" aria-label={t("unread")} />
                  ) : null}
                  {n.title}
                </span>
                <span className="text-sm">{n.body}</span>
                <span className="text-xs text-muted-foreground">{formatDateTime(n.created_at, locale)}</span>
              </button>
            </li>
          ))}
        </ul>
        {lastPage > 1 ? (
          <nav aria-label={t("pages")} className="flex items-center justify-between gap-3">
            <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>
              {t("previous")}
            </Button>
            <span className="text-sm text-muted-foreground">{t("page", { page, last: lastPage })}</span>
            <Button variant="outline" size="sm" disabled={page >= lastPage} onClick={() => setPage(page + 1)}>
              {t("next")}
            </Button>
          </nav>
        ) : null}
      </section>

      <PreferencesSection />
    </div>
  );
}

function PreferencesSection() {
  const t = useTranslations("web.notifications");
  const errorText = useErrorText();
  const queryClient = useQueryClient();
  const prefs = useMeNotificationPreferencesShow({
    query: { select: (r) => success(r).data as Preferences },
  });
  const save = useMutation({
    mutationFn: async (change: NotificationPreferencesRequestPreferencesItem) =>
      success(await meNotificationPreferencesUpdate({ preferences: [change] })).data,
    onSuccess: (data) =>
      queryClient.setQueryData(getMeNotificationPreferencesShowQueryKey(), {
        data: { data },
        status: 200,
        headers: new Headers(),
      }),
  });

  return (
    <section aria-labelledby="preferences-title" className="grid gap-3">
      <div className="grid gap-1">
        <h2 id="preferences-title" className="text-lg font-bold">
          {t("preferencesTitle")}
        </h2>
        <p className="text-sm text-muted-foreground">{t("preferencesIntro")}</p>
      </div>
      {prefs.isError || save.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(prefs.error ?? save.error)}
        </p>
      ) : null}
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b text-left">
              <th scope="col" className="py-2 pr-3 font-semibold">
                {t("event")}
              </th>
              {(["in_app", "email", "push"] as const).map((c) => (
                <th key={c} scope="col" className="px-3 py-2 text-center font-semibold">
                  {t(`channels.${c}`)}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {(prefs.data ?? []).map((row) => (
              <tr key={row.type} className="border-b">
                <th scope="row" className="py-2 pr-3 text-left font-normal">
                  {t(`types.${row.type as "new_sale"}`)}
                </th>
                {(["in_app", "email", "push"] as const).map((c) => (
                  <td key={c} className="px-3 py-2 text-center">
                    {c in row.channels ? (
                      <input
                        type="checkbox"
                        className="size-4 accent-primary"
                        aria-label={`${t(`types.${row.type as "new_sale"}`)} — ${t(`channels.${c}`)}`}
                        checked={row.channels[c] ?? true}
                        disabled={save.isPending}
                        onChange={(e) =>
                          save.mutate({
                            type: row.type as NotificationPreferencesRequestPreferencesItem["type"],
                            channel: c,
                            enabled: e.target.checked,
                          })
                        }
                      />
                    ) : (
                      <span className="text-muted-foreground" aria-label={t("notAvailable")}>
                        —
                      </span>
                    )}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="text-xs text-muted-foreground">{t("pushSoon")}</p>
    </section>
  );
}
