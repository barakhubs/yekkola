import type { Locale } from "@yekkola/i18n";
import { MoneyText } from "@yekkola/ui/blocks/money-text";
import { Button } from "@yekkola/ui/components/button";
import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { getTranslations, setRequestLocale } from "next-intl/server";

import { CourseGrid } from "@/features/catalog/components/course-grid";
import { Link } from "@/i18n/navigation";
import { getBundle } from "@/lib/public-api";

type Props = { params: Promise<{ locale: string; slug: string }> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { slug } = await params;
  const bundle = await getBundle(slug);

  return bundle ? { title: bundle.title, description: bundle.description ?? undefined } : {};
}

/** A bundle on sale: its courses at one price (PRD-05 FR-13). Courses the student owns are left out at checkout. */
export default async function BundlePage({ params }: Props) {
  const { locale: rawLocale, slug } = await params;
  const locale = rawLocale as Locale;
  setRequestLocale(locale);
  const bundle = await getBundle(slug);
  if (!bundle) notFound();
  const t = await getTranslations("web.bundle");
  const saves = bundle.list_total.amount_minor > bundle.price.amount_minor;

  return (
    <div className="mx-auto grid max-w-6xl gap-8 px-4 py-8 sm:py-12">
      <header className="grid gap-4">
        <p className="text-sm font-semibold text-amber-700 dark:text-amber-300">{t("eyebrow")}</p>
        <h1 className="text-3xl font-extrabold sm:text-4xl">{bundle.title}</h1>
        {bundle.description ? <p className="max-w-3xl text-muted-foreground">{bundle.description}</p> : null}
        <div className="flex flex-wrap items-center gap-4">
          <MoneyText value={bundle.price} locale={locale} className="text-2xl font-extrabold" />
          {saves ? (
            <span className="text-sm text-muted-foreground">
              {t("insteadOf")}{" "}
              <MoneyText value={bundle.list_total} locale={locale} className="line-through" />
            </span>
          ) : null}
          <Button asChild size="lg">
            <Link href={`/checkout?bundle=${bundle.id}`}>{t("buy")}</Link>
          </Button>
        </div>
      </header>
      <section aria-labelledby="bundle-courses" className="grid gap-4">
        <h2 id="bundle-courses" className="text-xl font-bold">
          {t("courses", { count: bundle.courses.length })}
        </h2>
        <CourseGrid courses={bundle.courses} locale={locale} />
      </section>
    </div>
  );
}
