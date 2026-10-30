import type { Locale } from "@yekkola/i18n";
import type { Metadata } from "next";
import { getTranslations, setRequestLocale } from "next-intl/server";

import { PayoutForm } from "@/features/studio/onboarding/payout-form";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;
  const t = await getTranslations({ locale: locale as Locale, namespace: "web.onboarding" });

  return { title: t("payoutTitle") };
}

/** Payout method, confirmed by SMS code (PRD-02 FR-09). */
export default async function PayoutsPage({ params }: { params: Promise<{ locale: string }> }) {
  setRequestLocale((await params).locale as Locale);

  return <PayoutForm />;
}
