import type { Locale } from "@yekkola/i18n";
import { setRequestLocale } from "next-intl/server";

import { ReviewTab } from "@/features/studio/components/review-tab";

/** Submit checklist, review outcome, publishing (PRD-03 FR-10–FR-17). */
export default async function ReviewPage({ params }: { params: Promise<{ locale: string; id: string }> }) {
  const { locale, id } = await params;
  setRequestLocale(locale as Locale);

  return <ReviewTab courseId={id} />;
}
