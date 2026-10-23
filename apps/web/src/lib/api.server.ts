import "server-only";

import { configureApiClient } from "@yekkola/api-client";
import { getLocale } from "next-intl/server";

/**
 * Server Components fetch public data with the generated fetchers; each request sends its own locale.
 * Imported once by the root layout.
 */
configureApiClient({
  baseUrl: process.env.API_INTERNAL_URL ?? process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000",
  locale: () => getLocale(),
});
