import "../globals.css";
import "@/lib/api.server";

import type { Metadata, Viewport } from "next";
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
  const t = await getTranslations({ locale: locale as Locale, namespace: "common" });

  return {
    metadataBase: new URL(process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000"),
    title: { default: `${t("appName")} — ${t("tagline")}`, template: `%s · ${t("appName")}` },
    description: t("tagline"),
    alternates: { languages: { fr: "/", en: "/en" } },
  };
}

export const viewport: Viewport = {
  themeColor: "#2c3892",
  width: "device-width",
  initialScale: 1,
};

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
