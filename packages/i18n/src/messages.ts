import type { Locale } from "./locales";

import type fr from "../messages/fr.json";

export type Messages = typeof fr;

/** Loads one catalogue (only the requested locale ends up in the request). */
export async function loadMessages(locale: Locale): Promise<Messages> {
  return locale === "en"
    ? (await import("../messages/en.json")).default
    : (await import("../messages/fr.json")).default;
}
