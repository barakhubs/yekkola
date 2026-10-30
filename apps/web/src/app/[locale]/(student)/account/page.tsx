import type { Locale } from "@yekkola/i18n";
import type { Metadata } from "next";
import { getTranslations, setRequestLocale } from "next-intl/server";

import { AccountPage } from "@/features/account/account-page";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;
  const t = await getTranslations({ locale: locale as Locale, namespace: "web.account" });

  return { title: t("title") };
}

/** My account: profile, phone number, devices, data export, deletion (PRD-01). */
export default async function AccountRoute({ params }: { params: Promise<{ locale: string }> }) {
  setRequestLocale((await params).locale as Locale);

  return <AccountPage />;
}
