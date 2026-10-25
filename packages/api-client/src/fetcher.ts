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

const isBrowser = (): boolean => typeof window !== "undefined";

function readCookie(name: string): string | undefined {
  if (!isBrowser()) return undefined;
  const match = document.cookie.split("; ").find((part) => part.startsWith(`${name}=`));
  return match ? decodeURIComponent(match.slice(name.length + 1)) : undefined;
}

let csrfRequest: Promise<void> | null = null;

/** Gets a fresh XSRF cookie when there is none (or when the session behind it expired: `force`). */
async function ensureCsrfCookie(force = false): Promise<string | undefined> {
  if (force || !readCookie("XSRF-TOKEN")) {
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

/**
 * Fetches the XSRF cookie ahead of time, so writes sent while the page is closing (`keepalive`) don't have to
 * wait for a network round trip that would never finish.
 */
export async function primeCsrf(): Promise<void> {
  if (isBrowser()) await ensureCsrfCookie();
}

/** Never throws: an empty or malformed JSON body (e.g. a proxy error page) reads as undefined. */
async function readBody(response: Response): Promise<unknown> {
  if (response.status === 204) return undefined;
  const type = response.headers.get("content-type") ?? "";
  if (type.includes("application/pdf") || type.includes("octet-stream")) return response.blob();
  const text = await response.text();
  if (type.includes("application/json") || type.includes("+json")) {
    try {
      return text === "" ? undefined : JSON.parse(text);
    } catch {
      return undefined;
    }
  }
  return text;
}

async function send(url: string, options: RequestInit, method: string, forceCsrf = false): Promise<Response> {
  const headers = new Headers(options.headers);
  headers.set("Accept", "application/json");
  headers.set("Accept-Language", await config.locale());
  if (config.headers) {
    new Headers(await config.headers()).forEach((value, key) => headers.set(key, value));
  }
  if (isBrowser() && UNSAFE.has(method)) {
    const token = await ensureCsrfCookie(forceCsrf);
    if (token) headers.set("X-XSRF-TOKEN", token);
  }

  return globalThis.fetch(`${config.baseUrl}${API_PREFIX}${url}`, {
    ...options,
    method,
    headers,
    credentials: "include",
  });
}

/** Orval mutator: returns `{ data, status, headers }` as the generated fetch client expects. */
export async function apiFetch<T>(url: string, options: RequestInit = {}): Promise<T> {
  const method = (options.method ?? "GET").toUpperCase();
  let response = await send(url, options, method);
  // 419: the session behind the XSRF cookie expired — refresh the token and try once more.
  if (response.status === 419 && isBrowser() && UNSAFE.has(method)) {
    response = await send(url, options, method, true);
  }
  const data = await readBody(response);

  if (!response.ok) {
    throw new ApiError(
      response.status,
      (typeof data === "object" && data !== null ? data : {}) as ApiErrorBody,
    );
  }

  return { data, status: response.status, headers: response.headers } as T;
}

/**
 * The success body of a generated fetcher's response. Generated response types also list error statuses,
 * but apiFetch throws ApiError for those, so a resolved response is always a 2xx.
 */
export function success<R extends { status: number; data: unknown }>(
  response: R,
): Extract<R, { status: 200 | 201 | 202 }>["data"] {
  return response.data as Extract<R, { status: 200 | 201 | 202 }>["data"];
}

/** Orval: every generated hook's error type. apiFetch throws ApiError for any non-2xx response. */
export type ErrorType<_Error> = ApiError;
/** Orval: request bodies are sent as given. */
export type BodyType<BodyData> = BodyData;

export default apiFetch;
