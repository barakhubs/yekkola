import "server-only";

import { configureApiClient } from "@yekkola/api-client";
import { getLocale } from "next-intl/server";

/**
 * Server Components fetch public data with the generated fetchers; each request sends its own locale.
 * Imported by the root layout; server code that runs without it (route handlers, sitemap.ts, OG images)
 * must import this module too.
 */
configureApiClient({
  baseUrl: process.env.API_INTERNAL_URL ?? process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000",
  locale: () => getLocale(),
});
