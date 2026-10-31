"use client";

import { useMutation } from "@tanstack/react-query";
import { success } from "@yekkola/api-client";
import { meImpersonationStop } from "@yekkola/api-client/endpoints/impersonation";
import { formatDateTime, type Locale } from "@yekkola/i18n";
import { Button } from "@yekkola/ui/components/button";
import { Eye } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";

import { useSession } from "@/features/auth/hooks/use-session";

/**
 * Shown while a Yekkola super admin views the site as this account (owner request, 2026-10-31): who, until when,
 * and the way back to the back office. Payments and account changes are refused meanwhile (the API decides).
 */
export function ImpersonationBanner() {
  const t = useTranslations("web.impersonation");
  const locale = useLocale() as Locale;
  const { user } = useSession();
  const stop = useMutation({
    mutationFn: async () => success(await meImpersonationStop()).data,
    onSettled: (data) => {
      if (data?.redirect_to) window.location.assign(data.redirect_to);
      else window.location.reload();
    },
  });

  if (!user?.impersonation) return null;

  return (
    <div
      role="status"
      className="flex flex-wrap items-center justify-center gap-3 bg-amber-300 px-4 py-2 text-sm font-semibold text-indigo-950"
    >
      <Eye aria-hidden className="size-4" />
      {t("banner", {
        name: user.name ?? "—",
        by: user.impersonation.by_name ?? "—",
        until: formatDateTime(user.impersonation.expires_at, locale),
      })}
      <Button size="sm" variant="outline" onClick={() => stop.mutate()} disabled={stop.isPending}>
        {t("stop")}
      </Button>
    </div>
  );
}
