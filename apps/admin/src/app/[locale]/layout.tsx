import "../globals.css";

import type { Metadata } from "next";
import { hasLocale, NextIntlClientProvider } from "next-intl";
import { getTranslations, setRequestLocale } from "next-intl/server";
import { Nunito } from "next/font/google";
import { notFound } from "next/navigation";
import type { ReactNode } from "react";

import type { Locale } from "@yekkola/i18n";

import { routing } from "@/i18n/routing";
import { Providers } from "@/lib/providers";

const nunito = Nunito({
  subsets: ["latin", "latin-ext"],
  display: "swap",
  variable: "--font-nunito",
});

export function generateStaticParams() {
  return routing.locales.map((locale) => ({ locale }));
}

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;
  const t = await getTranslations({ locale: locale as Locale, namespace: "admin" });

  // The back office is never indexed.
  return { title: t("title"), robots: { index: false, follow: false } };
}

export default async function LocaleLayout({
  children,
  params,
}: {
  children: ReactNode;
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;
  if (!hasLocale(routing.locales, locale)) {
    notFound();
  }
  setRequestLocale(locale);

  return (
    <html lang={locale} className={nunito.variable}>
      <body className="min-h-dvh">
        <NextIntlClientProvider>
          <Providers>{children}</Providers>
        </NextIntlClientProvider>
      </body>
    </html>
  );
}
