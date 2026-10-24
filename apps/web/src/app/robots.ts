import type { MetadataRoute } from "next";

const siteUrl = (process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000").replace(/\/$/, "");

/** Public pages are crawlable; signed-in areas and certificates (personal names) are not (PRD-04 FR-10). */
export default function robots(): MetadataRoute.Robots {
  const privatePaths = [
    "/my-courses",
    "/learn",
    "/checkout",
    "/orders",
    "/account",
    "/studio",
    "/login",
    "/certificates",
  ];

  return {
    rules: [
      {
        userAgent: "*",
        allow: "/",
        disallow: [...privatePaths, ...privatePaths.map((p) => `/en${p}`)],
      },
    ],
    sitemap: `${siteUrl}/sitemap.xml`,
  };
}
