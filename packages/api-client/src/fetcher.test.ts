import { afterEach, describe, expect, it, vi } from "vitest";

import { ApiError, apiFetch, configureApiClient } from "./fetcher";

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { "content-type": "application/json" } });
}

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("apiFetch", () => {
  it("prefixes /api/v1, sends the locale, and returns data with the status", async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ data: { status: "ok" } }));
    vi.stubGlobal("fetch", fetchMock);
    configureApiClient({ baseUrl: "https://api.example.test", locale: async () => "en" });

    const result = await apiFetch<{ data: unknown; status: number }>("/ping");

    expect(result.status).toBe(200);
    expect(result.data).toEqual({ data: { status: "ok" } });
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("https://api.example.test/api/v1/ping");
    expect(new Headers(init.headers).get("Accept-Language")).toBe("en");
    expect(init.credentials).toBe("include");
  });

  it("throws the error envelope with field errors", async () => {
    vi.stubGlobal(
      "fetch",
      vi
        .fn()
        .mockResolvedValue(
          jsonResponse(
            { code: "validation_failed", message: "Invalid.", errors: { rating: ["Too high.", "x"] } },
            422,
          ),
        ),
    );

    const error = await apiFetch("/courses/1/review", { method: "GET" }).catch((e: unknown) => e);

    expect(error).toBeInstanceOf(ApiError);
    expect((error as ApiError).status).toBe(422);
    expect((error as ApiError).code).toBe("validation_failed");
    expect((error as ApiError).fieldErrors()).toEqual({ rating: "Too high." });
  });

  it("returns no data on 204", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(new Response(null, { status: 204 })));

    const result = await apiFetch<{ data: unknown; status: number }>("/me/wishlist/1");

    expect(result.status).toBe(204);
    expect(result.data).toBeUndefined();
  });
});

describe("apiFetch in the browser", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("fetches the XSRF cookie before a write, and refreshes it once on 419", async () => {
    let cookie = "";
    vi.stubGlobal("window", {});
    vi.stubGlobal("document", {
      get cookie() {
        return cookie;
      },
    });
    const fetchMock = vi.fn(async (url: string, _init?: RequestInit) => {
      if (url.endsWith("/sanctum/csrf-cookie")) {
        cookie = `XSRF-TOKEN=${encodeURIComponent(`token-${fetchMock.mock.calls.length}`)}`;
        return new Response(null, { status: 204 });
      }
      return fetchMock.mock.calls.length < 3
        ? jsonResponse({ message: "CSRF token mismatch." }, 419)
        : jsonResponse({ ok: true }, 201);
    });
    vi.stubGlobal("fetch", fetchMock);
    configureApiClient({ baseUrl: "https://api.example.test", locale: () => "fr" });

    const result = await apiFetch<{ status: number }>("/courses/1/enroll", { method: "POST" });

    expect(result.status).toBe(201);
    const urls = fetchMock.mock.calls.map(([url]) => url);
    expect(urls).toEqual([
      "https://api.example.test/sanctum/csrf-cookie",
      "https://api.example.test/api/v1/courses/1/enroll",
      "https://api.example.test/sanctum/csrf-cookie",
      "https://api.example.test/api/v1/courses/1/enroll",
    ]);
    const lastInit = fetchMock.mock.calls[3]?.[1] as RequestInit;
    expect(new Headers(lastInit.headers).get("X-XSRF-TOKEN")).toBe("token-3");
  });

  it("turns an empty JSON error body into an ApiError", async () => {
    vi.stubGlobal(
      "fetch",
      vi
        .fn()
        .mockResolvedValue(
          new Response("", { status: 502, headers: { "content-type": "application/json" } }),
        ),
    );

    const error = await apiFetch("/ping").catch((e: unknown) => e);

    expect(error).toBeInstanceOf(ApiError);
    expect((error as ApiError).status).toBe(502);
  });
});
