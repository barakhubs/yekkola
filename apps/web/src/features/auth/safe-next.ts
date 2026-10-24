import { locales } from "@yekkola/i18n";

/**
 * Where to go after signing in: only a path on this site, without its locale prefix (the locale-aware router
 * adds the current one). Anything else (another origin, `//host`, `/\host`, a scheme) falls back, so `?next=`
 * can't be used to send people elsewhere (open redirect).
 */
export function safeNext(next: string | null | undefined, fallback = "/my-courses"): string {
  if (
    !next ||
    next.length > 500 ||
    !next.startsWith("/") ||
    next.startsWith("//") ||
    next.startsWith("/\\")
  ) {
    return fallback;
  }
  // Control characters and backslashes can be normalised by browsers into another host.
  if (/[\u0000-\u001f\\]/.test(next)) {
    return fallback;
  }

  const prefix = locales.find(
    (l) => next === `/${l}` || next.startsWith(`/${l}/`) || next.startsWith(`/${l}?`),
  );
  if (prefix) {
    const rest = next.slice(prefix.length + 1);
    return rest === "" ? "/" : rest.startsWith("?") ? `/${rest}` : rest;
  }

  return next;
}
