import type { Locale } from "@yekkola/i18n";
import { PageHeader } from "@yekkola/ui/blocks/page-header";
import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { getTranslations, setRequestLocale } from "next-intl/server";

import { findCategory } from "@/features/catalog/categories";
import { parseCatalogueQuery, type SearchParams } from "@/features/catalog/catalogue-params";
import { CatalogueView } from "@/features/catalog/components/catalogue-view";
import { CategoryList } from "@/features/catalog/components/category-list";
import { Link } from "@/i18n/navigation";
import { getCategories } from "@/lib/public-api";
import { breadcrumbJsonLd, JsonLd } from "@/lib/json-ld";
import { localeAlternates } from "@/lib/seo";

interface Props {
  params: Promise<{ locale: string; slug: string }>;
  searchParams: Promise<SearchParams>;
}

export async function generateMetadata({ params, searchParams }: Props): Promise<Metadata> {
  const { locale, slug } = await params;
  const found = findCategory(await getCategories(), slug);
  if (!found) {
    return {};
  }
  const query = parseCatalogueQuery(await searchParams);

  return {
    title: found.category.name,
    description: found.category.description ?? undefined,
    alternates: localeAlternates(`/categories/${slug}`, locale as Locale),
    robots:
      query.page > 1 || query.price || query.level || query.language || query.audience || query.sort
        ? { index: false, follow: true }
        : undefined,
  };
}

/** A category with its subcategories and courses (PRD-04 FR-02; courses of subcategories included). */
export default async function CategoryPage({ params, searchParams }: Props) {
  const { locale, slug } = await params;
  setRequestLocale(locale as Locale);
  const found = findCategory(await getCategories(), slug);
  if (!found) {
    notFound();
  }
  const { category, parent } = found;
  const query = parseCatalogueQuery(await searchParams);
  const t = await getTranslations("web");

  const crumbs = [
    { name: t("nav.courses"), href: "/courses" },
    ...(parent ? [{ name: parent.name, href: `/categories/${parent.slug}` }] : []),
    { name: category.name, href: `/categories/${category.slug}` },
  ];

  return (
    <div className="mx-auto grid max-w-6xl gap-8 px-4 py-8 sm:py-12">
      <JsonLd data={breadcrumbJsonLd(crumbs, locale as Locale)} />
      <nav aria-label={t("catalog.breadcrumb")} className="text-sm text-muted-foreground">
        <ol className="flex flex-wrap gap-1">
          {crumbs.slice(0, -1).map((crumb) => (
            <li key={crumb.href} className="after:ml-1 after:content-['›']">
              <Link href={crumb.href} className="hover:underline">
                {crumb.name}
              </Link>
            </li>
          ))}
        </ol>
      </nav>
      <PageHeader title={category.name} description={category.description ?? undefined} />
      <CategoryList categories={(parent ?? category).children} activeSlug={category.slug} />
      <CatalogueView
        query={query}
        locale={locale as Locale}
        path={`/categories/${category.slug}`}
        category={category.slug}
      />
    </div>
  );
}
