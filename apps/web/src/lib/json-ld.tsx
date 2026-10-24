import type { CourseDetailResource, PublicProfessorResource } from "@yekkola/api-client/model";
import { type Locale, moneyToMajor } from "@yekkola/i18n";

import { getPathname } from "@/i18n/navigation";

/** Structured data for search engines (PRD-04 FR-10). */

const siteUrl = (process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000").replace(/\/$/, "");

function absolute(href: string, locale: Locale): string {
  return `${siteUrl}${getPathname({ href, locale })}`;
}

export function JsonLd({ data }: { data: Record<string, unknown> }) {
  return (
    <script
      type="application/ld+json"
      // JSON.stringify escapes quotes; "<" is escaped so the payload can't close the script tag.
      dangerouslySetInnerHTML={{ __html: JSON.stringify(data).replace(/</g, "\\u003c") }}
    />
  );
}

export function breadcrumbJsonLd(crumbs: { name: string; href: string }[], locale: Locale) {
  return {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    itemListElement: crumbs.map((crumb, index) => ({
      "@type": "ListItem",
      position: index + 1,
      name: crumb.name,
      item: absolute(crumb.href, locale),
    })),
  };
}

function personJsonLd(professor: PublicProfessorResource, locale: Locale) {
  return {
    "@type": "Person",
    name: professor.display_name,
    url: absolute(`/professors/${professor.slug}`, locale),
    ...(professor.headline ? { jobTitle: professor.headline } : {}),
    ...(professor.avatar_url ? { image: professor.avatar_url } : {}),
  };
}

export function professorJsonLd(professor: PublicProfessorResource, locale: Locale) {
  return { "@context": "https://schema.org", ...personJsonLd(professor, locale) };
}

export function courseJsonLd(course: CourseDetailResource, locale: Locale) {
  return {
    "@context": "https://schema.org",
    "@type": "Course",
    name: course.title,
    description: course.subtitle ?? course.description ?? course.title ?? "",
    url: absolute(`/courses/${course.slug}`, locale),
    inLanguage: course.language,
    provider: { "@type": "Organization", name: "Yekkola", url: siteUrl },
    instructor: personJsonLd(course.professor, locale),
    ...(course.cover_url ? { image: course.cover_url } : {}),
    ...(course.rating !== null
      ? {
          aggregateRating: {
            "@type": "AggregateRating",
            ratingValue: course.rating,
            ratingCount: course.rating_count,
            bestRating: 5,
          },
        }
      : {}),
    offers: {
      "@type": "Offer",
      category: course.is_free ? "Free" : "Paid",
      price: course.is_free || !course.price ? 0 : moneyToMajor(course.price),
      ...(course.price ? { priceCurrency: course.price.currency } : {}),
      availability: "https://schema.org/InStock",
    },
    hasCourseInstance: {
      "@type": "CourseInstance",
      courseMode: "Online",
      courseWorkload: `PT${Math.max(1, Math.round(course.total_duration_seconds / 60))}M`,
    },
  };
}
