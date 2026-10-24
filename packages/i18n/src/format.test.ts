import { describe, expect, it } from "vitest";
import { formatBytes, formatDuration, formatMoney, formatPhone } from "./format";

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
  const plain = (text: string) => text.replace(/\s/g, " ");

  it("uses minutes, then hours and minutes", () => {
    expect(plain(formatDuration(720, "fr"))).toBe("12 min");
    expect(plain(formatDuration(3900, "fr"))).toBe("1 h 5 min");
    expect(plain(formatDuration(3600, "en"))).toBe("1 hr");
  });
});

describe("formatBytes", () => {
  const plain = (text: string) => text.replace(/\s/g, " ");

  it("uses megabytes, then gigabytes", () => {
    expect(plain(formatBytes(48_500_000, "en"))).toBe("49 MB");
    expect(plain(formatBytes(1_250_000_000, "fr"))).toBe("1,3 Go");
    expect(plain(formatBytes(2_400_000, "fr"))).toBe("2,4 Mo");
  });
});
