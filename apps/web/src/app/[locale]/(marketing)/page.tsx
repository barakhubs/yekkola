import type { Locale } from "@yekkola/i18n";
import { EmptyState } from "@yekkola/ui/blocks/empty-state";
import type { Metadata } from "next";
import { getTranslations, setRequestLocale } from "next-intl/server";

import { CategoryList } from "@/features/catalog/components/category-list";
import { CourseRail } from "@/features/catalog/components/course-rail";
import { SearchForm } from "@/features/catalog/components/search-form";
import { getCategories, getHomeRails } from "@/lib/public-api";
import { localeAlternates } from "@/lib/seo";

/** Rebuilt in the background at most every 5 minutes (ISR). */
export const revalidate = 300;

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;

  return { alternates: localeAlternates("/", locale as Locale) };
}

/** Home (PRD-04 FR-01): search first, then admin-featured, popular, new, and free rails, then categories. */
export default async function HomePage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;
  setRequestLocale(locale as Locale);
  const t = await getTranslations("web");
  const [rails, categories] = await Promise.all([getHomeRails(), getCategories()]);
  const empty = Object.values(rails).every((rail) => rail.length === 0);

  return (
    <>
      <section className="bg-indigo-50 dark:bg-indigo-950">
        <div className="mx-auto grid max-w-6xl gap-6 px-4 py-12 sm:py-20">
          <h1 className="max-w-2xl text-3xl leading-tight font-extrabold text-indigo-900 sm:text-5xl dark:text-indigo-100">
            {t("home.title")}
          </h1>
          <p className="max-w-xl text-lg text-muted-foreground">{t("home.subtitle")}</p>
          <div className="grid max-w-2xl gap-2">
            <h2 className="text-sm font-bold">{t("home.searchTitle")}</h2>
            <SearchForm locale={locale as Locale} />
          </div>
        </div>
      </section>

      <div className="mx-auto grid max-w-6xl gap-12 px-4 py-10 sm:py-14">
        {empty ? <EmptyState title={t("home.emptyRails")} /> : null}
        <CourseRail
          id="featured"
          title={t("catalog.rails.featured")}
          courses={rails.featured}
          locale={locale as Locale}
          priority
        />
        <CourseRail
          id="popular"
          title={t("catalog.rails.popular")}
          courses={rails.popular}
          locale={locale as Locale}
          href="/courses?sort=popular"
        />
        <CourseRail
          id="free"
          title={t("catalog.rails.free")}
          courses={rails.free}
          locale={locale as Locale}
          href="/courses?price=free"
        />
        <CourseRail
          id="new"
          title={t("catalog.rails.new")}
          courses={rails.new}
          locale={locale as Locale}
          href="/courses?sort=newest"
        />
        {categories.some((c) => c.course_count > 0) ? (
          <section aria-labelledby="home-categories" className="grid gap-4">
            <h2 id="home-categories" className="text-xl font-bold sm:text-2xl">
              {t("catalog.categories")}
            </h2>
            <CategoryList categories={categories} />
          </section>
        ) : null}
      </div>
    </>
  );
}
