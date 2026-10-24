import type { Locale } from "@yekkola/i18n";
import type { Metadata } from "next";

import { getPathname } from "@/i18n/navigation";
import { routing } from "@/i18n/routing";

/**
 * Canonical URL and hreflang alternates for one page (call from each page's generateMetadata — never from
 * a layout, or every child page would claim the same URLs). French is x-default.
 */
export function localeAlternates(href: string, locale: Locale): Metadata["alternates"] {
  const languages = Object.fromEntries(
    routing.locales.map((l) => [l, getPathname({ href, locale: l })]),
  ) as Record<Locale, string>;

  return {
    canonical: languages[locale],
    languages: { ...languages, "x-default": languages[routing.defaultLocale] },
  };
}
