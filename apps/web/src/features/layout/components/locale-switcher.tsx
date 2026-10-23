"use client";

import type { Locale } from "@yekkola/i18n";
import { Button } from "@yekkola/ui/components/button";
import { useLocale, useTranslations } from "next-intl";

import { Link, usePathname } from "@/i18n/navigation";

/** Switches between French and English on the same page. */
export function LocaleSwitcher() {
  const locale = useLocale() as Locale;
  const pathname = usePathname();
  const t = useTranslations("common");
  const other: Locale = locale === "fr" ? "en" : "fr";

  return (
    <Button asChild variant="ghost" size="sm" aria-label={t("language")}>
      <Link href={pathname} locale={other} lang={other}>
        {other.toUpperCase()}
      </Link>
    </Button>
  );
}
