"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { success } from "@yekkola/api-client";
import {
  getMeNotificationsIndexQueryKey,
  getMeNotificationsUnreadCountQueryKey,
  meNotificationsRead,
  useMeNotificationsIndex,
  useMeNotificationsUnreadCount,
} from "@yekkola/api-client/endpoints/notification";
import type { NotificationResource } from "@yekkola/api-client/model";
import { formatDateTime, type Locale } from "@yekkola/i18n";
import { PageHeader } from "@yekkola/ui/blocks/page-header";
import { Button } from "@yekkola/ui/components/button";
import { cn } from "@yekkola/ui/lib/utils";
import { Bell } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useState } from "react";

import { useErrorText } from "@/features/common/errors";
import { useTableLabels } from "@/features/common/table";
import { Link, useRouter } from "@/i18n/navigation";

/** The back office shows staff alerts only (PRD-10 FR-09); everything else is on the web app. */
const ADMIN = { "filter[app]": "admin" } as const;

/** Sidebar bell: unread staff alerts, refreshed every minute. */
export function StaffAlertsBell() {
  const t = useTranslations("admin.alerts");
  const unread = useMeNotificationsUnreadCount(ADMIN, {
    query: { select: (r) => success(r).data.unread, refetchInterval: 60_000, refetchOnWindowFocus: true },
  });
  const count = unread.data ?? 0;

  return (
    <Button asChild size="sm" variant="ghost" className="relative">
      <Link href="/alerts" aria-label={count > 0 ? t("bellUnread", { count }) : t("bell")}>
        <Bell aria-hidden />
        {count > 0 ? (
          <span
            aria-hidden
            className="absolute -top-0.5 -right-0.5 grid min-w-4 place-items-center rounded-full bg-destructive px-1 text-[0.625rem] leading-4 font-bold text-white"
          >
            {count > 99 ? "99+" : count}
          </span>
        ) : null}
      </Link>
    </Button>
  );
}

/** Staff alerts (PRD-10 FR-09): newest first; opening one marks it read and follows its link. */
export function StaffAlertsPage() {
  const t = useTranslations("admin.alerts");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const labels = useTableLabels();
  const router = useRouter();
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const list = useMeNotificationsIndex(
    { ...ADMIN, page, per_page: 20 },
    { query: { select: (r) => success(r), placeholderData: (previous) => previous } },
  );
  const read = useMutation({
    mutationFn: async (ids?: string[]) => success(await meNotificationsRead(ids ? { ids } : {}, ADMIN)),
    onSuccess: () =>
      Promise.all([
        queryClient.invalidateQueries({ queryKey: getMeNotificationsIndexQueryKey() }),
        queryClient.invalidateQueries({ queryKey: getMeNotificationsUnreadCountQueryKey() }),
      ]),
  });
  const lastPage = list.data?.meta.last_page ?? 1;
  const open = (n: NotificationResource) => {
    if (n.read_at === null) read.mutate([n.id]);
    if (n.link) router.push(n.link);
  };

  return (
    <div className="mx-auto grid max-w-3xl gap-6 px-4 py-8 sm:px-6">
      <PageHeader
        title={t("title")}
        description={t("intro")}
        actions={
          (list.data?.data ?? []).some((n) => n.read_at === null) ? (
            <Button
              variant="outline"
              size="sm"
              disabled={read.isPending}
              onClick={() => read.mutate(undefined)}
            >
              {t("markAllRead")}
            </Button>
          ) : undefined
        }
      />
      {list.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(list.error)}
        </p>
      ) : null}
      {list.data?.data.length === 0 ? <p className="text-sm text-muted-foreground">{t("empty")}</p> : null}
      <ul className="grid gap-2" aria-busy={list.isFetching}>
        {(list.data?.data ?? []).map((n) => (
          <li key={n.id}>
            <button
              type="button"
              onClick={() => open(n)}
              className={cn(
                "grid w-full gap-1 rounded-lg border bg-background p-3 text-left hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none",
                n.read_at === null && "border-destructive/40",
              )}
            >
              <span className="flex items-center gap-2 font-semibold">
                {n.read_at === null ? (
                  <span className="size-2 shrink-0 rounded-full bg-destructive" aria-label={t("unread")} />
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
        <nav aria-label={labels.pagination} className="flex items-center justify-between gap-4">
          <Button variant="outline" disabled={page <= 1} onClick={() => setPage(page - 1)}>
            {labels.previous}
          </Button>
          <span className="text-sm text-muted-foreground">{labels.page(page, lastPage)}</span>
          <Button variant="outline" disabled={page >= lastPage} onClick={() => setPage(page + 1)}>
            {labels.next}
          </Button>
        </nav>
      ) : null}
    </div>
  );
}
