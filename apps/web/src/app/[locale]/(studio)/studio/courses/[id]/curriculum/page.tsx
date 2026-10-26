import type { Locale } from "@yekkola/i18n";
import { setRequestLocale } from "next-intl/server";

import { CurriculumTab } from "@/features/studio/components/curriculum-tab";

/** Sections and lessons, their order and content (PRD-03 FR-03–FR-08). */
export default async function CurriculumPage({
  params,
}: {
  params: Promise<{ locale: string; id: string }>;
}) {
  const { locale, id } = await params;
  setRequestLocale(locale as Locale);

  return <CurriculumTab courseId={id} />;
}
