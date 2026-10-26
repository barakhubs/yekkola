import type { NextConfig } from "next";
import createNextIntlPlugin from "next-intl/plugin";

const withNextIntl = createNextIntlPlugin("./src/i18n/request.ts");

/**
 * Covers and avatars come from the API's public disk (an object-storage bucket in staging/production).
 * NEXT_PUBLIC_IMAGE_HOSTS lists extra origins, comma-separated; the API origin is always allowed.
 */
const imageOrigins = [
  process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000",
  ...(process.env.NEXT_PUBLIC_IMAGE_HOSTS ?? "").split(","),
]
  .map((origin) => origin.trim())
  .filter(Boolean)
  .map((origin) => new URL(origin));

const nextConfig: NextConfig = {
  reactStrictMode: true,
  poweredByHeader: false,
  // Workspace packages ship TypeScript source.
  transpilePackages: ["@yekkola/ui", "@yekkola/i18n", "@yekkola/api-client"],
  images: {
    remotePatterns: imageOrigins.map((url) => ({
      protocol: url.protocol.replace(":", "") as "http" | "https",
      hostname: url.hostname,
      port: url.port,
    })),
    // Small, modern formats for slow connections.
    formats: ["image/avif", "image/webp"],
    // Locally the API is on localhost, which the optimizer refuses by default (SSRF guard); never in production.
    dangerouslyAllowLocalIP: process.env.NODE_ENV === "development",
  },
};

export default withNextIntl(nextConfig);
