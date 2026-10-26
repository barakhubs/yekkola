import { describe, expect, it } from "vitest";

import { safeNext } from "./safe-next";

describe("safeNext", () => {
  it("keeps back-office paths", () => {
    expect(safeNext("/applications?status=submitted")).toBe("/applications?status=submitted");
    expect(safeNext("/en/moderation/courses")).toBe("/moderation/courses");
    expect(safeNext("/fr")).toBe("/");
  });

  it("never goes back to the sign-in page", () => {
    expect(safeNext("/login")).toBe("/");
    expect(safeNext("/en/login?next=/applications")).toBe("/");
  });

  it("refuses anything that could leave the site", () => {
    for (const next of [
      "https://evil.test",
      "//evil.test",
      "/\\evil.test",
      "/%5Cevil.test".replace("%5C", "\\"),
      "/\tevil.test",
      "/\n/evil.test",
      "javascript:alert(1)",
      "evil.test",
      "",
    ]) {
      expect(safeNext(next)).toBe("/");
    }
    expect(safeNext(null)).toBe("/");
  });
});
