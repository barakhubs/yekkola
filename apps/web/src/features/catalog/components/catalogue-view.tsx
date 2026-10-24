import type { Locale } from "@yekkola/i18n";
import { EmptyState } from "@yekkola/ui/blocks/empty-state";
import { Button } from "@yekkola/ui/components/button";
import { SearchX } from "lucide-react";
import { getTranslations } from "next-intl/server";

import { getPathname, Link } from "@/i18n/navigation";
import { getCourses } from "@/lib/public-api";
import { visitorHeaders } from "@/lib/visitor";

import { type CatalogueQuery, toApiParams, toQueryString } from "../catalogue-params";
import { CatalogueFilters } from "./catalogue-filters";
import { CourseGrid } from "./course-grid";
import { Pagination } from "./pagination";

interface CatalogueViewProps {
  query: CatalogueQuery;
  locale: Locale;
  /** Page path without locale: `/courses` or `/categories/{slug}`. */
  path: string;
  category?: string;
}

/** Course list with filters, result count, empty state, and pagination — shared by search and category pages. */
export async function CatalogueView({ query, locale, path, category }: CatalogueViewProps) {
  const t = await getTranslations("web.search");
  const result = await getCourses(toApiParams(query, category), query.q ? await visitorHeaders() : {});
  const href = (overrides: Partial<CatalogueQuery>) => `${path}${toQueryString({ ...query, ...overrides })}`;

  return (
    <div className="grid gap-6">
      <CatalogueFilters
        query={query}
        action={getPathname({ href: path, locale })}
        clearHref={`${path}${toQueryString({ q: query.q })}`}
      />
      <h2 className="sr-only">{t("resultsHeading")}</h2>
      <p className="text-sm text-muted-foreground" aria-live="polite">
        {t("results", { count: result.meta.total })}
      </p>
      {result.data.length === 0 ? (
        <EmptyState
          icon={<SearchX />}
          title={t("empty")}
          description={t("emptyHint")}
          action={
            <div className="flex flex-wrap justify-center gap-2">
              <Button asChild variant="accent">
                <Link href="/courses?price=free">{t("browseFree")}</Link>
              </Button>
              <Button asChild variant="outline">
                <Link href="/#home-categories">{t("browseCategories")}</Link>
              </Button>
            </div>
          }
        />
      ) : (
        <CourseGrid courses={result.data} locale={locale} />
      )}
      <Pagination
        page={result.meta.current_page}
        lastPage={result.meta.last_page}
        hrefFor={(page) => href({ page })}
      />
    </div>
  );
}
