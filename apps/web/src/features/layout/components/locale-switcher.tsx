"use client";

import type { Locale } from "@yekkola/i18n";
import { Button } from "@yekkola/ui/components/button";
import { useLocale, useTranslations } from "next-intl";
import { useSearchParams } from "next/navigation";

import { Link, usePathname } from "@/i18n/navigation";

/** Switches between French and English on the same page, keeping filters in the query string. */
export function LocaleSwitcher() {
  const locale = useLocale() as Locale;
  const pathname = usePathname();
  const query = Object.fromEntries(useSearchParams());
  const t = useTranslations("common.languages");
  const other: Locale = locale === "fr" ? "en" : "fr";

  return (
    <Button asChild variant="ghost" size="sm">
      <Link href={{ pathname, query }} locale={other} hrefLang={other}>
        {other.toUpperCase()}
        <span className="sr-only"> — {t(other)}</span>
      </Link>
    </Button>
  );
}
