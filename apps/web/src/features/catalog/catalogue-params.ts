import type { CoursesIndexParams } from "@yekkola/api-client/model";

/** Catalogue filters as they appear in the URL (PRD-04 FR-02, FR-03). Unknown values are dropped. */
export const FILTER_OPTIONS = {
  price: ["free", "paid"],
  language: ["fr", "en"],
  level: ["beginner", "intermediate", "advanced", "all"],
  audience: ["exetat", "secondaire", "universite", "professionnel"],
  sort: ["relevance", "popular", "newest", "rating", "price_asc", "price_desc"],
} as const;

export type FilterName = keyof typeof FILTER_OPTIONS;

export type SearchParams = Record<string, string | string[] | undefined>;

export const PER_PAGE = 24;

function first(value: string | string[] | undefined): string | undefined {
  return Array.isArray(value) ? value[0] : value;
}

function pick<N extends FilterName>(
  name: N,
  params: SearchParams,
): (typeof FILTER_OPTIONS)[N][number] | undefined {
  const value = first(params[name]);
  return (FILTER_OPTIONS[name] as readonly string[]).includes(value ?? "")
    ? (value as (typeof FILTER_OPTIONS)[N][number])
    : undefined;
}

export interface CatalogueQuery {
  q?: string;
  price?: (typeof FILTER_OPTIONS)["price"][number];
  language?: (typeof FILTER_OPTIONS)["language"][number];
  level?: (typeof FILTER_OPTIONS)["level"][number];
  audience?: (typeof FILTER_OPTIONS)["audience"][number];
  sort?: (typeof FILTER_OPTIONS)["sort"][number];
  page: number;
}

export function parseCatalogueQuery(params: SearchParams): CatalogueQuery {
  const q = first(params.q)?.trim().slice(0, 100);
  const page = Number.parseInt(first(params.page) ?? "1", 10);

  return {
    q: q ? q : undefined,
    price: pick("price", params),
    language: pick("language", params),
    level: pick("level", params),
    audience: pick("audience", params),
    sort: pick("sort", params),
    page: Number.isFinite(page) && page > 0 ? Math.min(page, 500) : 1,
  };
}

export function toApiParams(query: CatalogueQuery, category?: string): CoursesIndexParams {
  return {
    q: query.q,
    category,
    price: query.price,
    language: query.language,
    level: query.level,
    audience: query.audience,
    sort: query.sort,
    page: query.page,
    per_page: PER_PAGE,
  };
}

/** The query string for a catalogue link, without empty values (and without page 1). */
export function toQueryString(query: Partial<CatalogueQuery>): string {
  const entries = Object.entries(query).filter(
    ([key, value]) => value !== undefined && value !== "" && !(key === "page" && value === 1),
  );

  return entries.length ? `?${new URLSearchParams(entries.map(([k, v]) => [k, String(v)])).toString()}` : "";
}

export function hasFilters(query: CatalogueQuery): boolean {
  return Boolean(query.price || query.language || query.level || query.audience || query.sort);
}
