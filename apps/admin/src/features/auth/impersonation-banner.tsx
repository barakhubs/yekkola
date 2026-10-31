"use client";

import { useMutation } from "@tanstack/react-query";
import { success } from "@yekkola/api-client";
import { meImpersonationStop } from "@yekkola/api-client/endpoints/impersonation";
import { formatDateTime, type Locale } from "@yekkola/i18n";
import { Button } from "@yekkola/ui/components/button";
import { Eye } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";

import { useSession } from "./session";

/** Shown while a super admin views the back office as someone else: who, until when, and a way back. */
export function ImpersonationBanner() {
  const t = useTranslations("admin.impersonation");
  const locale = useLocale() as Locale;
  const { user } = useSession();
  const stop = useMutation({
    mutationFn: async () => success(await meImpersonationStop()).data,
    // A full reload: every cached answer belonged to the other account.
    onSettled: (data) => window.location.assign(data?.redirect_to ?? "/"),
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
