import { describe, expect, it } from "vitest";

import { bannerOf, courseStatusOf } from "./status";

const live = { id: "v1" };

describe("bannerOf", () => {
  it("says what students see and where the draft stands", () => {
    expect(bannerOf({ status: "draft", live_version: null, draft_version: { state: "draft" } })).toBe(
      "draft",
    );
    expect(bannerOf({ status: "in_review", live_version: null, draft_version: { state: "in_review" } })).toBe(
      "in_review",
    );
    expect(bannerOf({ status: "published", live_version: live, draft_version: null })).toBe("live");
    expect(bannerOf({ status: "published", live_version: live, draft_version: { state: "draft" } })).toBe(
      "live_draft",
    );
    expect(bannerOf({ status: "published", live_version: live, draft_version: { state: "in_review" } })).toBe(
      "live_in_review",
    );
  });

  it("puts a rejection first, then offline and archived states", () => {
    expect(bannerOf({ status: "published", live_version: live, draft_version: { state: "rejected" } })).toBe(
      "rejected",
    );
    expect(bannerOf({ status: "unpublished", live_version: live, draft_version: null })).toBe("unpublished");
    expect(bannerOf({ status: "archived", live_version: live, draft_version: { state: "rejected" } })).toBe(
      "archived",
    );
  });
});

describe("courseStatusOf", () => {
  it("falls back to draft for an unknown status", () => {
    expect(courseStatusOf("published")).toBe("published");
    expect(courseStatusOf("something_new")).toBe("draft");
  });
});
