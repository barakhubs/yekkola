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
