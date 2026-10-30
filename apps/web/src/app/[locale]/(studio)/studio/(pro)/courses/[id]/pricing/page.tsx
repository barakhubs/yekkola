import type { Locale } from "@yekkola/i18n";
import { setRequestLocale } from "next-intl/server";

import { PricingTab } from "@/features/studio/components/pricing-tab";

/** Free or paid, price and currency — applies at once, to new orders only (PRD-03 FR-09, FR-15). */
export default async function PricingPage({ params }: { params: Promise<{ locale: string; id: string }> }) {
  const { locale, id } = await params;
  setRequestLocale(locale as Locale);

  return <PricingTab courseId={id} />;
}
