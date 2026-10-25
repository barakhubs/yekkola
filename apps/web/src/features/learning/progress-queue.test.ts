import { ApiError } from "@yekkola/api-client";
import { describe, expect, it } from "vitest";

import { mergeProgress, shouldRetry } from "./progress-queue";

const uid = "01JZZZZZZZZZZZZZZZZZZZZZZZ";

describe("mergeProgress", () => {
  it("keeps the furthest position when an older, failed update comes back", () => {
    const failed = { lesson_uid: uid, position_seconds: 1140, client_updated_at: "2026-10-25T10:00:00Z" };
    const newer = { lesson_uid: uid, position_seconds: 120, client_updated_at: "2026-10-25T10:01:00Z" };

    expect(mergeProgress(newer, failed)).toEqual({
      lesson_uid: uid,
      position_seconds: 1140,
      client_updated_at: "2026-10-25T10:01:00Z",
    });
  });

  it("never loses a completion", () => {
    const done = { lesson_uid: uid, completed: true, client_updated_at: "2026-10-25T10:00:00Z" };
    const later = { lesson_uid: uid, position_seconds: 30, client_updated_at: "2026-10-25T10:02:00Z" };

    expect(mergeProgress(done, later)).toMatchObject({ completed: true, position_seconds: 30 });
    expect(mergeProgress(undefined, later)).toBe(later);
  });
});

describe("shouldRetry", () => {
  it("retries transient failures only", () => {
    expect(shouldRetry(new TypeError("Failed to fetch"))).toBe(true);
    expect(shouldRetry(new ApiError(503, {}))).toBe(true);
    expect(shouldRetry(new ApiError(429, {}))).toBe(true);
    expect(shouldRetry(new ApiError(403, { code: "enrollment.required" }))).toBe(false);
    expect(shouldRetry(new ApiError(422, {}))).toBe(false);
  });
});
