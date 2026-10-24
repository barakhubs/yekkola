import type { Locale } from "@yekkola/i18n";
import { getTranslations, setRequestLocale } from "next-intl/server";
import type { ReactNode } from "react";

import { SiteFooter } from "@/features/layout/components/site-footer";
import { SiteHeader } from "@/features/layout/components/site-header";

export default async function MarketingLayout({
  children,
  params,
}: {
  children: ReactNode;
  params: Promise<{ locale: string }>;
}) {
  // Every layout and page sets the locale, so public pages stay statically rendered (ISR).
  setRequestLocale((await params).locale as Locale);
  const t = await getTranslations("common");

  return (
    <div className="flex min-h-dvh flex-col">
      <a
        href="#content"
        className="sr-only focus:not-sr-only focus:absolute focus:m-2 focus:rounded-md focus:bg-primary focus:px-3 focus:py-2 focus:text-primary-foreground"
      >
        {t("skipToContent")}
      </a>
      <SiteHeader />
      <main id="content" className="flex-1">
        {children}
      </main>
      <SiteFooter />
    </div>
  );
}
