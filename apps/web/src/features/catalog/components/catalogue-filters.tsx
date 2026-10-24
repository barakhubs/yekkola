import { Button } from "@yekkola/ui/components/button";
import { getTranslations } from "next-intl/server";

import { Link } from "@/i18n/navigation";

import { type CatalogueQuery, FILTER_OPTIONS, type FilterName, hasFilters } from "../catalogue-params";
import { OmitEmptyFields } from "./omit-empty-fields";

interface CatalogueFiltersProps {
  query: CatalogueQuery;
  /** Path the form submits to (the catalogue or a category page). */
  action: string;
  /** Path for "Clear filters": same page, search kept. */
  clearHref: string;
}

const selectClass =
  "h-10 w-full rounded-md border border-input bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none";

/**
 * Filters and sort (PRD-04 FR-02) as a GET form: works without JavaScript, state lives in the URL (shareable,
 * back-button safe). Applied with the button — never on change, so keyboard users can browse options.
 */
export async function CatalogueFilters({ query, action, clearHref }: CatalogueFiltersProps) {
  const t = await getTranslations("web.filters");
  const catalog = await getTranslations("web.catalog");

  const label = (name: FilterName, value: string): string => {
    switch (name) {
      case "price":
        return t(value as "free" | "paid");
      case "language":
        return catalog(`languages.${value as "fr" | "en"}`);
      case "level":
        return catalog(`levels.${value as "beginner" | "intermediate" | "advanced" | "all"}`);
      case "audience":
        return catalog(`audiences.${value as "exetat" | "secondaire" | "universite" | "professionnel"}`);
      case "sort":
        return t(`sorts.${value as (typeof FILTER_OPTIONS)["sort"][number]}`);
    }
  };
  const any: Record<FilterName, string> = {
    price: t("priceAny"),
    language: t("languageAny"),
    level: t("levelAny"),
    audience: t("audienceAny"),
    // The API's own default: relevance when searching, popularity otherwise.
    sort: t(query.q ? "sorts.relevance" : "sorts.popular"),
  };
  const titles: Record<FilterName, string> = {
    price: t("price"),
    language: t("language"),
    level: t("level"),
    audience: t("audience"),
    sort: t("sort"),
  };
  const options = (name: FilterName): readonly string[] =>
    name === "sort"
      ? FILTER_OPTIONS.sort.filter((s) => s !== "relevance" && !(s === "popular" && !query.q))
      : FILTER_OPTIONS[name];

  return (
    <form
      id="catalogue-filters"
      action={action}
      method="get"
      className="grid gap-3 sm:grid-cols-2 lg:grid-cols-6"
    >
      {query.q ? <input type="hidden" name="q" value={query.q} /> : null}
      {(["price", "language", "level", "audience", "sort"] as const).map((name) => (
        <div key={name} className="grid gap-1">
          <label htmlFor={`filter-${name}`} className="text-xs font-semibold text-muted-foreground">
            {titles[name]}
          </label>
          <select id={`filter-${name}`} name={name} defaultValue={query[name] ?? ""} className={selectClass}>
            <option value="">{any[name]}</option>
            {options(name).map((value) => (
              <option key={value} value={value}>
                {label(name, value)}
              </option>
            ))}
          </select>
        </div>
      ))}
      <div className="flex items-end">
        <Button type="submit" variant="secondary" className="w-full">
          {t("apply")}
        </Button>
      </div>
      {hasFilters(query) ? (
        <div className="sm:col-span-2 lg:col-span-6">
          <Link
            href={clearHref}
            className="text-sm font-semibold text-primary underline-offset-4 hover:underline"
          >
            {t("clear")}
          </Link>
        </div>
      ) : null}
      <OmitEmptyFields formId="catalogue-filters" />
    </form>
  );
}
