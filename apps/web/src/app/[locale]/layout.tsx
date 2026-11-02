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
import { getBranding } from "@/lib/public-api";

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
  const branding = await getBranding();

  return {
    // Staff-uploaded images when set (PRD-09 FR-15); pages with their own picture override the share image.
    ...(branding.favicon_url ? { icons: { icon: branding.favicon_url, apple: branding.favicon_url } } : {}),
    ...(branding.share_image_url ? { openGraph: { images: [{ url: branding.share_image_url }] } } : {}),
    metadataBase: new URL(process.env.NEXT_PUBLIC_SITE_URL || "http://localhost:3000"),
    title: { default: `${t("appName")} — ${t("tagline")}`, template: `%s · ${t("appName")}` },
    description: t("tagline"),
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
