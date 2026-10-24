import { defaultLocale, locales } from "@yekkola/i18n";
import { defineRouting } from "next-intl/routing";

/** French at `/`, English at `/en` (project-context.md → Languages). */
export const routing = defineRouting({
  locales,
  defaultLocale,
  localePrefix: "as-needed",
});
