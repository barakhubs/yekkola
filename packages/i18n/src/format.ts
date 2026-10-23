import { intlTag, type Locale } from "./locales";

/** Money as the API sends it: integer minor units with an explicit currency (never a float). */
export interface MoneyValue {
  amount_minor: number;
  currency: string;
}

/** Minor-unit digits per currency (ISO 4217) — mirrors the API's Currency enum. */
const MINOR_UNITS: Record<string, number> = { USD: 2, CDF: 2 };

/**
 * "15,00 $US" / "$15.00". Francs are collected in whole units, so CDF hides centimes unless there are some.
 */
export function formatMoney(money: MoneyValue, locale: Locale): string {
  const digits = MINOR_UNITS[money.currency] ?? 2;
  const major = money.amount_minor / 10 ** digits;
  const whole = money.currency === "CDF" && money.amount_minor % 10 ** digits === 0;

  return new Intl.NumberFormat(intlTag(locale), {
    style: "currency",
    currency: money.currency,
    minimumFractionDigits: whole ? 0 : digits,
    maximumFractionDigits: whole ? 0 : digits,
  }).format(major);
}

export type DateStyle = "short" | "medium" | "long";

/** Dates from the API are ISO 8601 strings; shown in the reader's language. */
export function formatDate(iso: string, locale: Locale, style: DateStyle = "medium"): string {
  return new Intl.DateTimeFormat(intlTag(locale), { dateStyle: style }).format(new Date(iso));
}

export function formatDateTime(iso: string, locale: Locale): string {
  return new Intl.DateTimeFormat(intlTag(locale), { dateStyle: "medium", timeStyle: "short" }).format(
    new Date(iso),
  );
}

/** "+243 812 345 678" for DRC numbers (9 national digits); other numbers as given. */
export function formatPhone(e164: string): string {
  const match = /^\+243(\d{3})(\d{3})(\d{3})$/.exec(e164);
  return match ? `+243 ${match[1]} ${match[2]} ${match[3]}` : e164;
}

/** Lesson and course lengths: "1 h 05", "12 min". */
export function formatDuration(seconds: number, locale: Locale): string {
  const minutes = Math.round(seconds / 60);
  if (minutes < 60) {
    return `${minutes} min`;
  }
  const hours = Math.floor(minutes / 60);
  const rest = String(minutes % 60).padStart(2, "0");
  return locale === "fr" ? `${hours} h ${rest}` : `${hours}h ${rest}m`;
}
