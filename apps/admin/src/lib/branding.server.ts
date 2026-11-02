import { success } from "@yekkola/api-client";
import { brandingShow } from "@yekkola/api-client/endpoints/branding";

export interface Branding {
  logo_url: string | null;
  footer_logo_url: string | null;
  favicon_url: string | null;
  share_image_url: string | null;
}

const NONE: Branding = { logo_url: null, footer_logo_url: null, favicon_url: null, share_image_url: null };

/**
 * Brand images staff uploaded (PRD-09 FR-15), for server-rendered parts (favicon, sign-in pages). Cached for five
 * minutes; when the API can't be reached the built-in brand shows instead of failing the page.
 */
export async function getBranding(): Promise<Branding> {
  try {
    const options = { next: { revalidate: 300 }, signal: AbortSignal.timeout(5_000) } as RequestInit;

    return { ...NONE, ...success(await brandingShow(options)).data };
  } catch {
    return NONE;
  }
}
