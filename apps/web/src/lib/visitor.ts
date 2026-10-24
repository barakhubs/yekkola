import "server-only";

import { headers } from "next/headers";

/**
 * The visitor's IP, forwarded to the API on per-visitor requests (search, certificate checks) so its rate
 * limits apply to each visitor rather than to our web server. Reading headers makes the page dynamic —
 * only use it on pages that already are.
 */
export async function visitorHeaders(): Promise<Record<string, string>> {
  const h = await headers();
  const ip = h.get("x-real-ip") ?? h.get("x-forwarded-for")?.split(",")[0]?.trim();

  return ip ? { "X-Client-Ip": ip } : {};
}
