import type { Locale } from "@yekkola/i18n";
import { setRequestLocale } from "next-intl/server";

import { DetailsTab } from "@/features/studio/components/details-tab";

/** Title, description, outcomes, cover, category, level, audience (PRD-03 FR-02). */
export default async function DetailsPage({ params }: { params: Promise<{ locale: string; id: string }> }) {
  const { locale, id } = await params;
  setRequestLocale(locale as Locale);

  return <DetailsTab courseId={id} />;
}
