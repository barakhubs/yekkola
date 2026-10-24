import type { Locale } from "@yekkola/i18n";
import { PageHeader } from "@yekkola/ui/blocks/page-header";
import { getTranslations, setRequestLocale } from "next-intl/server";

export default async function ConsoleHomePage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;
  setRequestLocale(locale as Locale);
  const t = await getTranslations("admin.home");

  return (
    <main className="mx-auto max-w-6xl px-6 py-10">
      <PageHeader title={t("title")} description={t("subtitle")} />
    </main>
  );
}
