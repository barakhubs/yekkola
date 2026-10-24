import { describe, expect, it } from "vitest";

import { safeNext } from "./safe-next";

describe("safeNext", () => {
  it("keeps paths on this site", () => {
    expect(safeNext("/courses/algebre?tab=reviews")).toBe("/courses/algebre?tab=reviews");
    expect(safeNext("/en/my-courses")).toBe("/en/my-courses");
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
