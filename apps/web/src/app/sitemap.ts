import "@/lib/api.server";

import { defaultLocale, locales } from "@yekkola/i18n";
import type { MetadataRoute } from "next";

import { getPathname } from "@/i18n/navigation";
import { getCategories, getCourses } from "@/lib/public-api";

const siteUrl = (process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000").replace(/\/$/, "");

/** Regenerated hourly. */
export const revalidate = 3600;

/** Every listed course gets fetched page by page; capped so one sitemap stays under 50,000 URLs. */
const MAX_COURSE_PAGES = 100;

function entry(href: string, lastModified?: string | null): MetadataRoute.Sitemap[number] {
  const languages = Object.fromEntries(
    locales.map((l) => [l, `${siteUrl}${getPathname({ href, locale: l })}`]),
  );

  return {
    url: `${siteUrl}${getPathname({ href, locale: defaultLocale })}`,
    lastModified: lastModified ?? undefined,
    alternates: { languages },
  };
}

/** Public pages with fr/en alternates (PRD-04 FR-10). Professors are reached through their courses. */
export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const entries: MetadataRoute.Sitemap = [entry("/"), entry("/courses")];

  try {
    const categories = await getCategories();
    for (const category of categories) {
      entries.push(entry(`/categories/${category.slug}`));
      for (const child of category.children) {
        entries.push(entry(`/categories/${child.slug}`));
      }
    }

    const professors = new Set<string>();
    for (let page = 1; page <= MAX_COURSE_PAGES; page++) {
      const result = await getCourses({ page, per_page: 100, sort: "newest" });
      for (const course of result.data) {
        entries.push(entry(`/courses/${course.slug}`, course.published_at));
        professors.add(course.professor.slug);
      }
      if (page >= result.meta.last_page) break;
    }
    for (const slug of professors) {
      entries.push(entry(`/professors/${slug}`));
    }
  } catch {
    // API unreachable (e.g. at build time): serve the static pages; the next revalidation adds the rest.
  }

  return entries;
}
