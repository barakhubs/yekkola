/** UI languages (project-context.md → Languages). French is the default everywhere. */
export const locales = ["fr", "en"] as const;
export type Locale = (typeof locales)[number];
export const defaultLocale: Locale = "fr";

export function isLocale(value: unknown): value is Locale {
  return typeof value === "string" && (locales as readonly string[]).includes(value);
}

/** BCP 47 tag used for number and date formatting. */
export function intlTag(locale: Locale): string {
  return locale === "fr" ? "fr-CD" : "en";
}
