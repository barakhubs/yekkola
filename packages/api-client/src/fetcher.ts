/**
 * The one HTTP client for /api/v1 (docs/architecture.md §5.1). Every generated fetcher and hook goes
 * through apiFetch:
 * - base URL from NEXT_PUBLIC_API_URL (the API origin; `/api/v1` is added here);
 * - `Accept-Language` from the app's current locale, so errors and validation messages are translated;
 * - browsers use Sanctum cookie auth: credentials included, and unsafe methods carry the XSRF token
 *   (fetched once from /sanctum/csrf-cookie);
 * - non-2xx responses throw an ApiError carrying the API's error envelope (`code`, `errors`).
 */

const API_PREFIX = "/api/v1";
const UNSAFE = new Set(["POST", "PUT", "PATCH", "DELETE"]);

/** The API's error envelope (architecture §4.1): stable `code`, translated `message`, field `errors`. */
export interface ApiErrorBody {
  code?: string;
  message?: string;
  errors?: Record<string, string[]>;
  [key: string]: unknown;
}

export class ApiError extends Error {
  constructor(
    readonly status: number,
    readonly body: ApiErrorBody,
  ) {
    super(body.message ?? `HTTP ${status}`);
    this.name = "ApiError";
  }

  get code(): string | undefined {
    return this.body.code;
  }

  /** First validation message per field — for React Hook Form `setError`. */
  fieldErrors(): Record<string, string> {
    return Object.fromEntries(
      Object.entries(this.body.errors ?? {}).flatMap(([field, messages]) =>
        messages[0] ? [[field, messages[0]]] : [],
      ),
    );
  }
}

interface ClientConfig {
  baseUrl: string;
  /** Current UI locale; the server reads it per request (next-intl getLocale). */
  locale: () => string | Promise<string>;
  /** Extra headers per request, e.g. forwarded cookies on the server (never set in the browser). */
  headers?: () => HeadersInit | Promise<HeadersInit>;
}

const config: ClientConfig = {
  baseUrl: process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000",
  locale: () => "fr",
};

/** Called once by each app (locale source, optional server-side headers). */
export function configureApiClient(next: Partial<ClientConfig>): void {
  Object.assign(config, next);
}

const isBrowser = typeof window !== "undefined";

function readCookie(name: string): string | undefined {
  if (!isBrowser) return undefined;
  const match = document.cookie.split("; ").find((part) => part.startsWith(`${name}=`));
  return match ? decodeURIComponent(match.slice(name.length + 1)) : undefined;
}

let csrfRequest: Promise<void> | null = null;

async function ensureCsrfCookie(): Promise<string | undefined> {
  if (!readCookie("XSRF-TOKEN")) {
    csrfRequest ??= globalThis
      .fetch(`${config.baseUrl}/sanctum/csrf-cookie`, { credentials: "include" })
      .then(() => undefined)
      .finally(() => {
        csrfRequest = null;
      });
    await csrfRequest;
  }
  return readCookie("XSRF-TOKEN");
}

async function readBody(response: Response): Promise<unknown> {
  if (response.status === 204) return undefined;
  const type = response.headers.get("content-type") ?? "";
  if (type.includes("application/json") || type.includes("+json")) return response.json();
  if (type.includes("application/pdf") || type.includes("octet-stream")) return response.blob();
  return response.text();
}

/** Orval mutator: returns `{ data, status, headers }` as the generated fetch client expects. */
export async function apiFetch<T>(url: string, options: RequestInit = {}): Promise<T> {
  const method = (options.method ?? "GET").toUpperCase();
  const headers = new Headers(options.headers);
  headers.set("Accept", "application/json");
  headers.set("Accept-Language", await config.locale());
  if (config.headers) {
    new Headers(await config.headers()).forEach((value, key) => headers.set(key, value));
  }
  if (isBrowser && UNSAFE.has(method)) {
    const token = await ensureCsrfCookie();
    if (token) headers.set("X-XSRF-TOKEN", token);
  }

  const response = await globalThis.fetch(`${config.baseUrl}${API_PREFIX}${url}`, {
    ...options,
    method,
    headers,
    credentials: "include",
  });
  const data = await readBody(response);

  if (!response.ok) {
    throw new ApiError(
      response.status,
      (typeof data === "object" && data !== null ? data : {}) as ApiErrorBody,
    );
  }

  return { data, status: response.status, headers: response.headers } as T;
}

export default apiFetch;
