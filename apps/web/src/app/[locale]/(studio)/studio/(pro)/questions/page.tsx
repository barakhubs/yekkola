import type { Locale } from "@yekkola/i18n";
import type { Metadata } from "next";
import { getTranslations, setRequestLocale } from "next-intl/server";

import { QaInbox } from "@/features/studio/components/qa-inbox";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;
  const t = await getTranslations({ locale: locale as Locale, namespace: "web.qa" });

  return { title: t("inboxTitle") };
}

/** Students' questions on my courses (PRD-06 FR-13). */
export default async function QuestionsPage({ params }: { params: Promise<{ locale: string }> }) {
  setRequestLocale((await params).locale as Locale);

  return <QaInbox />;
}
