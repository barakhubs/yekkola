import type { Locale } from "@yekkola/i18n";

import { redirect } from "@/i18n/navigation";

/** The studio opens on the course list until the dashboard exists (TODO 2.5 analytics). */
export default async function StudioHome({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;

  redirect({ href: "/studio/courses", locale: locale as Locale });
}
