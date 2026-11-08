// Shared helpers for the k6 load tests (see README.md).
import http from "k6/http";
import { SharedArray } from "k6/data";

// 422 is an expected answer in the checkout run (already owned); other 4xx/5xx count as failures.
http.setResponseCallback(http.expectedStatuses({ min: 200, max: 299 }, 422));

export const BASE = (__ENV.API_URL || "http://localhost:8000").replace(/\/$/, "") + "/api/v1";

// Output of `php artisan loadtest:prepare` (tokens, free course + lessons, paid course).
const fixture = new SharedArray("fixture", () => [JSON.parse(open(__ENV.FIXTURE || "./fixture.json"))]);
export const data = () => fixture[0];

// p95 targets from project-context.md (non-functional requirements): reads < 300 ms, writes < 800 ms.
export const thresholds = {
  "http_req_duration{kind:read}": ["p(95)<300"],
  "http_req_duration{kind:write}": ["p(95)<800"],
  http_req_failed: ["rate<0.01"],
  checks: ["rate>0.99"],
};

/** Ramp to `VUS` (default 50) virtual users over a minute, hold for `DURATION` (default 5m), ramp down. */
export function stages() {
  const vus = Number(__ENV.VUS || 50);
  return [
    { duration: "1m", target: vus },
    { duration: __ENV.DURATION || "5m", target: vus },
    { duration: "30s", target: 0 },
  ];
}

/** Headers for a signed-in student: one token per virtual user, as on a phone. */
export function student() {
  const tokens = data().tokens;
  return {
    Authorization: `Bearer ${tokens[(__VU - 1) % tokens.length]}`,
    Accept: "application/json",
    "Content-Type": "application/json",
    "Accept-Language": "fr",
  };
}

/**
 * Headers for an anonymous visitor. Public pages reach the API through our web server, which proves itself with
 * WEB_SERVER_KEY and forwards the visitor's IP; with the key set, each virtual user counts as its own visitor
 * (otherwise the whole run shares one IP and its per-visitor limit).
 */
export function visitor() {
  const headers = { Accept: "application/json", "Accept-Language": "fr" };
  if (__ENV.WEB_SERVER_KEY) {
    headers["X-Web-Server-Key"] = __ENV.WEB_SERVER_KEY;
    headers["X-Client-Ip"] = `10.${(__VU >> 16) & 255}.${(__VU >> 8) & 255}.${__VU & 255}`;
  }
  return headers;
}

export const get = (path, headers, name) =>
  http.get(BASE + path, { headers, tags: { kind: "read", name: name || path } });

export const send = (method, path, body, headers, name) =>
  http.request(method, BASE + path, JSON.stringify(body), {
    headers,
    tags: { kind: "write", name: name || path },
  });

export const pick = (list) => list[Math.floor(Math.random() * list.length)];

export const uuid = () =>
  "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    return (c === "x" ? r : (r & 0x3) | 0x8).toString(16);
  });
