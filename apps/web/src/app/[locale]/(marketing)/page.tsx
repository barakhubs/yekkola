import type { Locale } from "@yekkola/i18n";
import { Button } from "@yekkola/ui/components/button";
import type { Metadata } from "next";
import { getTranslations, setRequestLocale } from "next-intl/server";

import { Link } from "@/i18n/navigation";
import { localeAlternates } from "@/lib/seo";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;

  return { alternates: localeAlternates("/", locale as Locale) };
}

export default async function HomePage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;
  setRequestLocale(locale as Locale);
  const t = await getTranslations("web.home");

  return (
    <section className="bg-indigo-50 dark:bg-indigo-950">
      <div className="mx-auto grid max-w-6xl gap-6 px-4 py-16 sm:py-24">
        <h1 className="max-w-2xl text-4xl leading-tight font-extrabold text-indigo-900 sm:text-5xl dark:text-indigo-100">
          {t("title")}
        </h1>
        <p className="max-w-xl text-lg text-muted-foreground">{t("subtitle")}</p>
        <div>
          <Button asChild size="lg">
            <Link href="/courses">{t("browse")}</Link>
          </Button>
        </div>
      </div>
    </section>
  );
}
