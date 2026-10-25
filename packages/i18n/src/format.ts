import { intlTag, type Locale } from "./locales";

/** Money as the API sends it: integer minor units with an explicit currency (never a float). */
export interface MoneyValue {
  amount_minor: number;
  currency: string;
}

/** Minor-unit digits per currency (ISO 4217) — mirrors the API's Currency enum. */
const MINOR_UNITS: Record<string, number> = { USD: 2, CDF: 2 };

/** The amount in major units (e.g. for structured data); never for display — use formatMoney. */
export function moneyToMajor(money: MoneyValue): number {
  return money.amount_minor / 10 ** (MINOR_UNITS[money.currency] ?? 2);
}

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

/** Lesson and course lengths, e.g. "12 min", "1 h 5 min" (unit names from Intl, per locale). */
export function formatDuration(seconds: number, locale: Locale): string {
  const unit = (value: number, name: "hour" | "minute") =>
    new Intl.NumberFormat(intlTag(locale), { style: "unit", unit: name, unitDisplay: "short" }).format(value);
  const minutes = Math.round(seconds / 60);
  if (minutes < 60) {
    return unit(minutes, "minute");
  }
  const hours = Math.floor(minutes / 60);
  const rest = minutes % 60;

  return rest === 0 ? unit(hours, "hour") : `${unit(hours, "hour")} ${unit(rest, "minute")}`;
}

/** Download sizes: "12 Mo" / "12 MB", "1,2 Go" / "1.2 GB" (decimal units, as phones show storage). */
export function formatBytes(bytes: number, locale: Locale): string {
  const gigabytes = bytes / 1_000_000_000;
  const [value, unit] =
    gigabytes >= 1 ? [gigabytes, "gigabyte"] : [Math.max(bytes / 1_000_000, 0.1), "megabyte"];

  return new Intl.NumberFormat(intlTag(locale), {
    style: "unit",
    unit,
    unitDisplay: "short",
    maximumFractionDigits: value < 10 ? 1 : 0,
  }).format(value);
}

/** An amount as an editable number ("15,00" / "15.00"), without currency or grouping — for price inputs. */
export function moneyInputValue(money: MoneyValue, locale: Locale): string {
  const digits = MINOR_UNITS[money.currency] ?? 2;
  const whole = money.amount_minor % 10 ** digits === 0 && money.currency === "CDF";

  return new Intl.NumberFormat(intlTag(locale), {
    useGrouping: false,
    minimumFractionDigits: whole ? 0 : digits,
    maximumFractionDigits: whole ? 0 : digits,
  }).format(money.amount_minor / 10 ** digits);
}

/**
 * A typed price ("15", "15,5", "25 000") in minor units, without float maths; null if it isn't an amount
 * (letters, two separators, more decimals than the currency has). The API still checks limits and steps.
 */
export function parseMoneyInput(text: string, currency: string): number | null {
  const digits = MINOR_UNITS[currency] ?? 2;
  const compact = text.replace(/[\s\u00a0\u202f]/g, "");
  const match = new RegExp(`^(\\d+)(?:[.,](\\d{1,${digits}}))?$`).exec(compact);
  if (!match?.[1]) {
    return null;
  }
  const fraction = (match[2] ?? "").padEnd(digits, "0");

  return Number(match[1]) * 10 ** digits + Number(fraction || "0");
}
