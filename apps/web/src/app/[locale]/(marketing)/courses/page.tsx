import type { Locale } from "@yekkola/i18n";
import { PageHeader } from "@yekkola/ui/blocks/page-header";
import type { Metadata } from "next";
import { getTranslations, setRequestLocale } from "next-intl/server";

import { parseCatalogueQuery, type SearchParams } from "@/features/catalog/catalogue-params";
import { CatalogueView } from "@/features/catalog/components/catalogue-view";
import { SearchForm } from "@/features/catalog/components/search-form";
import { localeAlternates } from "@/lib/seo";

interface Props {
  params: Promise<{ locale: string }>;
  searchParams: Promise<SearchParams>;
}

export async function generateMetadata({ params, searchParams }: Props): Promise<Metadata> {
  const { locale } = await params;
  const query = parseCatalogueQuery(await searchParams);
  const t = await getTranslations({ locale: locale as Locale, namespace: "web" });

  return {
    title: query.q ? t("search.resultsFor", { query: query.q }) : t("nav.courses"),
    alternates: localeAlternates("/courses", locale as Locale),
    // Filtered and searched variants are not separate pages for search engines.
    robots: query.q || query.page > 1 ? { index: false, follow: true } : undefined,
  };
}

/** Catalogue and search (PRD-04 FR-02, FR-03). */
export default async function CoursesPage({ params, searchParams }: Props) {
  const { locale } = await params;
  setRequestLocale(locale as Locale);
  const query = parseCatalogueQuery(await searchParams);
  const t = await getTranslations("web");

  return (
    <div className="mx-auto grid max-w-6xl gap-8 px-4 py-8 sm:py-12">
      <PageHeader title={query.q ? t("search.resultsFor", { query: query.q }) : t("nav.courses")} />
      <SearchForm locale={locale as Locale} defaultValue={query.q} />
      <CatalogueView query={query} locale={locale as Locale} path="/courses" />
    </div>
  );
}
