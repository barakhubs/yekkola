import type messages from "@yekkola/i18n/messages/fr.json";

import type { routing } from "@/i18n/routing";

/** Typed translation keys and locales for next-intl (French is the reference catalogue). */
declare module "next-intl" {
  interface AppConfig {
    Locale: (typeof routing.locales)[number];
    Messages: typeof messages;
  }
}
