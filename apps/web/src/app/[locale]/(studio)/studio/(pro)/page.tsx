import type { Locale } from "@yekkola/i18n";
import type { Metadata } from "next";
import { getTranslations, setRequestLocale } from "next-intl/server";

import { OnboardingHome } from "@/features/studio/onboarding/onboarding-home";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;
  const t = await getTranslations({ locale: locale as Locale, namespace: "web.onboarding" });

  return { title: t("homeTitle") };
}

/** Studio home: the onboarding checklist (PRD-02 §6). */
export default async function StudioHome({ params }: { params: Promise<{ locale: string }> }) {
  setRequestLocale((await params).locale as Locale);

  return <OnboardingHome />;
}
