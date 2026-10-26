import { locales } from "@yekkola/i18n";

/**
 * Where to go after signing in: only a path in the back office, without its locale prefix (the locale-aware
 * router adds the current one), never the sign-in page itself. Anything else (another origin, `//host`,
 * `/\host`, a scheme, control characters) falls back to the home page, so `?next=` can't send staff elsewhere
 * (open redirect). Same rules as apps/web.
 */
export function safeNext(next: string | null | undefined, fallback = "/"): string {
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
  let path = next;
  if (prefix) {
    const rest = next.slice(prefix.length + 1);
    path = rest === "" ? "/" : rest.startsWith("?") ? `/${rest}` : rest;
  }

  return path === "/login" || path.startsWith("/login?") || path.startsWith("/login/") ? fallback : path;
}
