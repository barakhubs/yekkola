import type { Locale } from "@yekkola/i18n";
import type { Metadata } from "next";
import { getTranslations, setRequestLocale } from "next-intl/server";

import { ApplicationFlow } from "@/features/application/application-flow";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;
  const t = await getTranslations({ locale: locale as Locale, namespace: "web.apply" });

  return { title: t("title") };
}

/** Apply to teach and follow the application (PRD-02 FR-01–FR-06). Any signed-in user. */
export default async function ApplyPage({ params }: { params: Promise<{ locale: string }> }) {
  setRequestLocale((await params).locale as Locale);

  return <ApplicationFlow />;
}
