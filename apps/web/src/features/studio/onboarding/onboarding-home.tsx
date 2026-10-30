"use client";

import { formatDateTime, type Locale } from "@yekkola/i18n";
import { PageHeader } from "@yekkola/ui/blocks/page-header";
import { Button } from "@yekkola/ui/components/button";
import { CheckCircle2, Circle } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";

import { Link } from "@/i18n/navigation";

import { useStudioProfile } from "./professor-shell";

const STEPS = [
  { key: "profile_complete", href: "/studio/profile" },
  { key: "kyc_verified", href: "/studio/verification" },
  { key: "payout_method_set", href: "/studio/payouts" },
  { key: "first_course", href: "/studio/courses" },
] as const;

/**
 * Studio home (PRD-02 §6): the onboarding checklist from the API — profile, identity, payout method, first course
 * — and whether the professor can be paid yet (BR-01).
 */
export function OnboardingHome() {
  const t = useTranslations("web.onboarding");
  const locale = useLocale() as Locale;
  const profile = useStudioProfile();
  const p = profile.data;
  if (!p) return null;

  const done = STEPS.filter((s) => p.onboarding[s.key]).length;

  return (
    <div className="mx-auto grid max-w-5xl gap-6 px-4 py-8">
      <PageHeader
        title={t("welcome", { name: p.display_name })}
        description={t("progress", { done, total: STEPS.length })}
      />
      <ol className="grid gap-3">
        {STEPS.map(({ key, href }) => {
          const ok = p.onboarding[key];
          return (
            <li
              key={key}
              className="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-background p-4"
            >
              <span className="flex items-start gap-3">
                {ok ? (
                  <CheckCircle2 aria-hidden className="mt-0.5 size-5 shrink-0 text-success" />
                ) : (
                  <Circle aria-hidden className="mt-0.5 size-5 shrink-0 text-muted-foreground" />
                )}
                <span className="grid gap-0.5">
                  <span className="font-semibold">
                    <span className="sr-only">{ok ? t("done") : t("todo")}: </span>
                    {t(`steps.${key}.title`)}
                  </span>
                  <span className="text-sm text-muted-foreground">
                    {key === "kyc_verified" && !ok
                      ? t(`kycStatus.${p.kyc_status as "none"}`)
                      : t(`steps.${key}.body`)}
                  </span>
                </span>
              </span>
              <Button asChild variant={ok ? "ghost" : "outline"} size="sm">
                <Link href={href}>{ok ? t("view") : t("start")}</Link>
              </Button>
            </li>
          );
        })}
      </ol>
      <p className="rounded-lg border bg-background p-4 text-sm">
        {p.is_payable
          ? t("payable")
          : p.payout_hold_until
            ? t("onHold", { date: formatDateTime(p.payout_hold_until, locale) })
            : t("notPayable")}
      </p>
    </div>
  );
}
