import type { Locale } from "@yekkola/i18n";
import type { Metadata } from "next";
import { getTranslations, setRequestLocale } from "next-intl/server";
import type { ReactNode } from "react";

import { CourseEditorShell } from "@/features/studio/components/course-editor-shell";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;
  const t = await getTranslations({ locale: locale as Locale, namespace: "web.studio" });

  return { title: t("editorTitle") };
}

/** Course editor: status banner and tabs Details · Curriculum · Pricing · Review (PRD-03 §6). */
export default async function CourseEditorLayout({
  children,
  params,
}: {
  children: ReactNode;
  params: Promise<{ locale: string; id: string }>;
}) {
  const { locale, id } = await params;
  setRequestLocale(locale as Locale);

  return <CourseEditorShell courseId={id}>{children}</CourseEditorShell>;
}
