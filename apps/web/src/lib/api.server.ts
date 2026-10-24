import "server-only";

import { configureApiClient } from "@yekkola/api-client";
import { defaultLocale } from "@yekkola/i18n";
import { getLocale } from "next-intl/server";

/**
 * Server Components fetch public data with the generated fetchers; each request sends its own locale and our
 * server key (the API then rate-limits per visitor, not per web server — see `visitorHeaders`). Imported by
 * the root layout; server code that runs without it (route handlers, sitemap.ts, OG images) imports it too.
 */
configureApiClient({
  baseUrl: process.env.API_INTERNAL_URL ?? process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000",
  locale: async () => {
    try {
      return await getLocale();
    } catch {
      return defaultLocale; // outside a localized route (sitemap, route handlers)
    }
  },
  headers: (): Record<string, string> =>
    process.env.WEB_SERVER_KEY ? { "X-Web-Server-Key": process.env.WEB_SERVER_KEY } : {},
});
