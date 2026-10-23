import { describe, expect, it } from "vitest";
import { formatDuration, formatMoney, formatPhone } from "./format";

describe("formatMoney", () => {
  it("formats minor units per locale", () => {
    expect(formatMoney({ amount_minor: 1500, currency: "USD" }, "en")).toBe("$15.00");
    expect(formatMoney({ amount_minor: 1500, currency: "USD" }, "fr").replace(/\s/g, " ")).toBe("15,00 $US");
  });

  it("hides centimes on whole francs", () => {
    const text = formatMoney({ amount_minor: 2_500_000, currency: "CDF" }, "fr").replace(/\s/g, " ");
    expect(text).toMatch(/^25 000 /);
    expect(text).not.toContain(",");
  });
});

describe("formatPhone", () => {
  it("groups DRC numbers and leaves others alone", () => {
    expect(formatPhone("+243812345678")).toBe("+243 812 345 678");
    expect(formatPhone("+256700000000")).toBe("+256700000000");
  });
});

describe("formatDuration", () => {
  it("uses minutes, then hours", () => {
    expect(formatDuration(720, "fr")).toBe("12 min");
    expect(formatDuration(3900, "fr")).toBe("1 h 05");
    expect(formatDuration(3900, "en")).toBe("1h 05m");
  });
});
