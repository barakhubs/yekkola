"use client";

import { success } from "@yekkola/api-client";
import { useMeNotificationsUnreadCount } from "@yekkola/api-client/endpoints/notification";
import { Button } from "@yekkola/ui/components/button";
import { Bell } from "lucide-react";
import { useTranslations } from "next-intl";

import { Link } from "@/i18n/navigation";

/** Header bell (PRD-10 FR-03): unread count, refreshed every minute while the page is open. */
export function NotificationBell() {
  const t = useTranslations("web.notifications");
  const unread = useMeNotificationsUnreadCount({
    query: { select: (r) => success(r).data.unread, refetchInterval: 60_000, refetchOnWindowFocus: true },
  });
  const count = unread.data ?? 0;

  return (
    <Button asChild size="sm" variant="ghost" className="relative">
      <Link href="/notifications" aria-label={count > 0 ? t("bellUnread", { count }) : t("bell")}>
        <Bell aria-hidden />
        {count > 0 ? (
          <span
            aria-hidden
            className="absolute -top-0.5 -right-0.5 grid min-w-4 place-items-center rounded-full bg-brand-accent px-1 text-[0.625rem] leading-4 font-bold text-primary"
          >
            {count > 99 ? "99+" : count}
          </span>
        ) : null}
      </Link>
    </Button>
  );
}
