import type { Locale } from "@yekkola/i18n";
import { setRequestLocale } from "next-intl/server";

import { LearningView } from "@/features/learning/components/learning-view";

/** The course's learning view, opened at the lesson to continue (PRD-06 FR-02). */
export default async function LearnCoursePage({
  params,
}: {
  params: Promise<{ locale: string; course: string }>;
}) {
  const { locale, course } = await params;
  setRequestLocale(locale as Locale);

  return <LearningView courseId={course} />;
}
