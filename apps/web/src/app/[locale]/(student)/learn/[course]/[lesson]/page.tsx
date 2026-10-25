import type { Locale } from "@yekkola/i18n";
import { setRequestLocale } from "next-intl/server";

import { LearningView } from "@/features/learning/components/learning-view";

/** One lesson of the learning view (PRD-06 FR-02); the lesson uid is stable across course versions. */
export default async function LearnLessonPage({
  params,
}: {
  params: Promise<{ locale: string; course: string; lesson: string }>;
}) {
  const { locale, course, lesson } = await params;
  setRequestLocale(locale as Locale);

  return <LearningView courseId={course} lessonUid={lesson} />;
}
