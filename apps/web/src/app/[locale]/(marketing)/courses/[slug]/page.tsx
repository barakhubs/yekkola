import { formatBytes, formatDate, formatDuration, type Locale } from "@yekkola/i18n";
import { Check, Download } from "lucide-react";
import type { Metadata } from "next";
import Image from "next/image";
import { notFound } from "next/navigation";
import { getTranslations, setRequestLocale } from "next-intl/server";

import { PriceTag } from "@/features/catalog/components/price-tag";
import { RatingStars } from "@/features/catalog/components/rating-stars";
import { Curriculum } from "@/features/course/components/curriculum";
import { EnrollButton } from "@/features/course/components/enroll-button";
import { PreviewButton } from "@/features/course/components/preview-button";
import { ReviewList } from "@/features/course/components/review-list";
import { ShareButtons } from "@/features/course/components/share-buttons";
import { ProfessorSummary } from "@/features/professor/components/professor-summary";
import { getPathname, Link } from "@/i18n/navigation";
import { breadcrumbJsonLd, courseJsonLd, JsonLd } from "@/lib/json-ld";
import { getCourse, getCourseReviews } from "@/lib/public-api";
import { localeAlternates } from "@/lib/seo";

interface Props {
  params: Promise<{ locale: string; slug: string }>;
}

/** Rendered on first visit, then cached and refreshed every 5 minutes (ISR); publishing revalidates by tag. */
export const revalidate = 300;

export function generateStaticParams() {
  return [];
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { locale, slug } = await params;
  const course = await getCourse(slug);
  if (!course) {
    return {};
  }

  return {
    title: course.title ?? undefined,
    description: course.subtitle ?? course.description?.slice(0, 160) ?? undefined,
    alternates: localeAlternates(`/courses/${slug}`, locale as Locale),
    openGraph: {
      type: "website",
      title: course.title ?? undefined,
      description: course.subtitle ?? undefined,
      images: course.cover_url ? [{ url: course.cover_url, width: 1280, height: 720 }] : undefined,
    },
  };
}

/** Course page (PRD-04 FR-05): unlisted courses are a 404 for visitors (FR-12). */
export default async function CoursePage({ params }: Props) {
  const { locale: rawLocale, slug } = await params;
  const locale = rawLocale as Locale;
  setRequestLocale(locale);
  const [course, reviews] = await Promise.all([getCourse(slug), getCourseReviews(slug)]);
  if (!course) {
    notFound();
  }
  const t = await getTranslations("web");
  const lessons = course.sections.flatMap((s) => s.lessons);
  const preview = lessons.find((l) => l.is_preview && (l.type === "video" || l.type === "audio"));
  const siteUrl = (process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000").replace(/\/$/, "");
  const pageUrl = `${siteUrl}${getPathname({ href: `/courses/${slug}`, locale })}`;
  const crumbs = [
    { name: t("nav.courses"), href: "/courses" },
    ...(course.category ? [{ name: course.category.name, href: `/categories/${course.category.slug}` }] : []),
    { name: course.title ?? slug, href: `/courses/${slug}` },
  ];

  return (
    <article>
      <JsonLd data={courseJsonLd(course, locale)} />
      <JsonLd data={breadcrumbJsonLd(crumbs, locale)} />

      <header className="bg-indigo-950 text-white">
        <div className="mx-auto grid max-w-6xl gap-6 px-4 py-8 sm:py-12 lg:grid-cols-[1fr_22rem]">
          <div className="grid content-start gap-4">
            {course.category ? (
              <Link
                href={`/categories/${course.category.slug}`}
                className="w-fit text-sm font-semibold text-amber-300 hover:underline"
              >
                {course.category.name}
              </Link>
            ) : null}
            <h1 className="text-3xl leading-tight font-extrabold sm:text-4xl">{course.title}</h1>
            {course.subtitle ? <p className="text-lg text-indigo-100">{course.subtitle}</p> : null}
            <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-indigo-100">
              <RatingStars rating={course.rating} count={course.rating_count} onDark />
              <span>{t("catalog.students", { count: course.enrollment_count })}</span>
              <span>
                {t.rich("catalog.by", {
                  name: course.professor.display_name,
                  professor: (chunks) => (
                    <Link
                      href={`/professors/${course.professor.slug}`}
                      className="font-bold text-white underline-offset-4 hover:underline"
                    >
                      {chunks}
                    </Link>
                  ),
                })}
              </span>
            </div>
            <p className="flex flex-wrap gap-x-4 gap-y-1 text-sm text-indigo-200">
              <span>
                {t("course.language", {
                  language: t(`catalog.languages.${course.language === "en" ? "en" : "fr"}`),
                })}
              </span>
              <span>
                {t("course.level", {
                  level: t(
                    `catalog.levels.${course.level as "beginner" | "intermediate" | "advanced" | "all"}`,
                  ),
                })}
              </span>
              {course.updated_at ? (
                <span>{t("course.updated", { date: formatDate(course.updated_at, locale) })}</span>
              ) : null}
            </p>
          </div>

          {/* Purchase box: on phones it follows the hero; on wide screens it sits beside it. */}
          <aside className="grid content-start gap-4 rounded-xl bg-background p-4 text-foreground shadow-lg">
            <div className="relative aspect-video overflow-hidden rounded-md bg-indigo-100">
              {course.cover_url ? (
                <Image
                  src={course.cover_url}
                  alt=""
                  fill
                  priority
                  sizes="(min-width: 1024px) 22rem, 100vw"
                  className="object-cover"
                />
              ) : null}
            </div>
            <PriceTag
              isFree={course.is_free}
              price={course.price}
              locale={locale}
              className="text-2xl font-extrabold"
            />
            <EnrollButton courseId={course.id} slug={slug} isFree={course.is_free} />
            {course.can_buy_for_others ? (
              <Link href={`/courses/${slug}/gift`} className="text-sm font-semibold text-primary underline">
                {t("course.payForStudent")}
              </Link>
            ) : null}
            {preview ? (
              <PreviewButton
                lessonUid={preview.uid}
                title={preview.title}
                kind={preview.type as "video" | "audio"}
              />
            ) : null}
            <ul className="grid gap-1 text-sm text-muted-foreground">
              <li>
                {t("course.curriculumSummary", {
                  sections: course.sections.length,
                  lessons: course.lesson_count,
                  duration: formatDuration(course.total_duration_seconds, locale),
                })}
              </li>
              {course.download_bytes > 0 ? (
                <li className="flex items-center gap-1">
                  <Download aria-hidden className="size-4" />
                  {t("course.downloadSize", { size: formatBytes(course.download_bytes, locale) })}
                </li>
              ) : null}
            </ul>
            <ShareButtons url={pageUrl} text={course.title ?? ""} />
          </aside>
        </div>
      </header>

      <div className="mx-auto grid max-w-6xl gap-10 px-4 py-10 lg:grid-cols-[1fr_22rem]">
        <div className="grid content-start gap-10">
          {course.outcomes.length > 0 ? (
            <section aria-labelledby="outcomes" className="grid gap-3 rounded-xl border p-5">
              <h2 id="outcomes" className="text-xl font-bold">
                {t("course.outcomes")}
              </h2>
              <ul className="grid gap-2 sm:grid-cols-2">
                {course.outcomes.map((outcome) => (
                  <li key={outcome} className="flex gap-2">
                    <Check aria-hidden className="mt-0.5 size-4 shrink-0 text-success" />
                    <span>{outcome}</span>
                  </li>
                ))}
              </ul>
            </section>
          ) : null}

          <section aria-labelledby="curriculum" className="grid gap-3">
            <h2 id="curriculum" className="text-xl font-bold">
              {t("course.curriculum")}
            </h2>
            <p className="text-sm text-muted-foreground">{t("course.downloadHint")}</p>
            <Curriculum sections={course.sections} locale={locale} />
          </section>

          {course.requirements.length > 0 ? (
            <section aria-labelledby="requirements" className="grid gap-3">
              <h2 id="requirements" className="text-xl font-bold">
                {t("course.requirements")}
              </h2>
              <ul className="list-disc space-y-1 pl-5">
                {course.requirements.map((requirement) => (
                  <li key={requirement}>{requirement}</li>
                ))}
              </ul>
            </section>
          ) : null}

          {course.description ? (
            <section aria-labelledby="description" className="grid gap-3">
              <h2 id="description" className="text-xl font-bold">
                {t("course.description")}
              </h2>
              <p className="whitespace-pre-line">{course.description}</p>
            </section>
          ) : null}

          <section aria-labelledby="reviews" className="grid gap-3">
            <h2 id="reviews" className="text-xl font-bold">
              {t("course.reviews")}
            </h2>
            <RatingStars rating={course.rating} count={course.rating_count} />
            <ReviewList reviews={reviews?.data ?? []} locale={locale} />
          </section>
        </div>

        <aside aria-labelledby="professor" className="grid content-start gap-3">
          <h2 id="professor" className="text-xl font-bold">
            {t("course.professor")}
          </h2>
          <ProfessorSummary professor={course.professor} variant="card" />
          {course.professor.bio ? (
            <p className="line-clamp-6 text-sm whitespace-pre-line">{course.professor.bio}</p>
          ) : null}
        </aside>
      </div>
    </article>
  );
}
