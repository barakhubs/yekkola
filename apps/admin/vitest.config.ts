import { defineConfig } from "vitest/config";

/** Unit tests for app logic (no DOM); pages and components are covered by Playwright journeys. */
export default defineConfig({
  test: { include: ["src/**/*.test.ts"] },
});
