import type { Locale } from "@yekkola/i18n";

import { redirect } from "@/i18n/navigation";

/** The editor opens on the Details tab. */
export default async function CourseEditorHome({
  params,
}: {
  params: Promise<{ locale: string; id: string }>;
}) {
  const { locale, id } = await params;

  redirect({ href: `/studio/courses/${id}/details`, locale: locale as Locale });
}
