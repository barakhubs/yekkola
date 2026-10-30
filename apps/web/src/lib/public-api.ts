import "server-only";

import "./api.server";

import { ApiError, success } from "@yekkola/api-client";
import { bundlesShow } from "@yekkola/api-client/endpoints/bundle";
import { categoriesIndex } from "@yekkola/api-client/endpoints/category";
import { certificatesVerify } from "@yekkola/api-client/endpoints/certificate";
import { coursesIndex, coursesShow, featured } from "@yekkola/api-client/endpoints/course";
import { professorsShow } from "@yekkola/api-client/endpoints/professor";
import { coursesReviewsIndex } from "@yekkola/api-client/endpoints/review";
import type { CoursesIndexParams } from "@yekkola/api-client/model";

/**
 * Public catalogue data for Server Components (PRD-04). Responses are cached by Next (ISR) for `revalidate`
 * seconds and tagged so a publish can refresh them on demand. A 404 from the API comes back as `null`.
 */

type CacheOptions = RequestInit & { next: { revalidate: number | false; tags?: string[] } };

/** How long public pages may be stale (seconds). The API also expires them by tag on changes (/api/revalidate). */
const REVALIDATE = { catalogue: 300, course: 300, rails: 300, categories: 3600 } as const;

function cached(revalidate: number, tags: string[] = []): CacheOptions {
  return { next: { revalidate, tags } };
}

/**
 * Pages prerendered at build time (home) may build where the API isn't reachable (CI, image builds): they
 * start empty and fill in at their first revalidation. At runtime, errors surface as usual.
 */
async function emptyDuringBuild<T>(fallback: T, load: () => Promise<T>): Promise<T> {
  try {
    return await load();
  } catch (error) {
    if (process.env.NEXT_PHASE === "phase-production-build") {
      return fallback;
    }
    throw error;
  }
}

async function orNull<R extends { status: number; data: unknown }>(
  request: Promise<R>,
): Promise<ReturnType<typeof success<R>> | null> {
  try {
    return success(await request);
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) {
      return null;
    }
    throw error;
  }
}

export async function getHomeRails() {
  return emptyDuringBuild(
    { featured: [], popular: [], new: [], free: [] },
    async () => success(await featured(cached(REVALIDATE.rails, ["courses"]))).data,
  );
}

export async function getCategories() {
  return emptyDuringBuild(
    [],
    async () => success(await categoriesIndex(cached(REVALIDATE.categories, ["categories"]))).data,
  );
}

/**
 * Filter and category combinations are bounded, so they're cached. Free-text searches are not (every query
 * would fill the cache) and count against the visitor's own rate limit (`visitor`: their forwarded IP).
 */
export async function getCourses(params: CoursesIndexParams, visitor: Record<string, string> = {}) {
  const options: RequestInit = params.q
    ? { cache: "no-store", headers: visitor }
    : cached(REVALIDATE.catalogue, ["courses"]);

  return success(await coursesIndex(params, options));
}

export async function getCourse(slug: string) {
  return (
    (await orNull(coursesShow(slug, cached(REVALIDATE.course, ["courses", `course:${slug}`]))))?.data ?? null
  );
}

export async function getBundle(slug: string) {
  return (
    (await orNull(bundlesShow(slug, cached(REVALIDATE.course, ["courses", `bundle:${slug}`]))))?.data ?? null
  );
}

/** Best effort: the course page still renders (without reviews) if this fails. */
export async function getCourseReviews(slug: string, page = 1) {
  try {
    return await orNull(
      coursesReviewsIndex(slug, { page, per_page: 10 }, cached(REVALIDATE.course, [`course:${slug}`])),
    );
  } catch {
    return null;
  }
}

export async function getProfessor(slug: string) {
  return (
    (await orNull(professorsShow(slug, cached(REVALIDATE.course, ["courses", `professor:${slug}`]))))?.data ??
    null
  );
}

/** Never cached (a revocation must show at once); counted per visitor. */
export async function verifyCertificate(serial: string, visitor: Record<string, string>) {
  return (await orNull(certificatesVerify(serial, { cache: "no-store", headers: visitor })))?.data ?? null;
}
