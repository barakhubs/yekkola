import { describe, expect, it } from "vitest";

import { safeNext } from "./safe-next";

describe("safeNext", () => {
  it("keeps paths on this site", () => {
    expect(safeNext("/courses/algebre?tab=reviews")).toBe("/courses/algebre?tab=reviews");
    expect(safeNext("/my-courses?state=completed")).toBe("/my-courses?state=completed");
  });

  it("drops the locale prefix (the router adds the current one)", () => {
    expect(safeNext("/en/my-courses")).toBe("/my-courses");
    expect(safeNext("/fr")).toBe("/");
    expect(safeNext("/en?x=1")).toBe("/?x=1");
    expect(safeNext("/entreprises")).toBe("/entreprises");
  });

  it("refuses anything that could leave the site", () => {
    for (const next of [
      "https://evil.test",
      "//evil.test",
      "/\\evil.test",
      "javascript:alert(1)",
      "evil.test",
      "/\tevil",
      "",
    ]) {
      expect(safeNext(next)).toBe("/my-courses");
    }
    expect(safeNext(null, "/")).toBe("/");
  });
});
