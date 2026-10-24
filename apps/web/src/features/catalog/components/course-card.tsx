import type { CourseCardResource } from "@yekkola/api-client/model";
import { formatDuration, type Locale } from "@yekkola/i18n";
import { BadgeCheck, BookOpen } from "lucide-react";
import Image from "next/image";
import { getTranslations } from "next-intl/server";

import { Link } from "@/i18n/navigation";

import { PriceTag } from "./price-tag";
import { RatingStars } from "./rating-stars";

interface CourseCardProps {
  course: CourseCardResource;
  locale: Locale;
  /** Above the fold (first row): load the cover eagerly for LCP. */
  priority?: boolean;
}

/** PRD-04 FR-04: cover, title, professor, rating, students, price or "Gratuit", duration, language. */
export async function CourseCard({ course, locale, priority = false }: CourseCardProps) {
  const t = await getTranslations("web");

  return (
    <article className="group relative flex flex-col overflow-hidden rounded-xl border bg-card shadow-sm transition-shadow hover:shadow-md">
      <div className="relative aspect-video bg-indigo-100 dark:bg-indigo-950">
        {course.cover_url ? (
          <Image
            src={course.cover_url}
            alt=""
            fill
            priority={priority}
            sizes="(min-width: 1024px) 25vw, (min-width: 640px) 50vw, 100vw"
            className="object-cover"
          />
        ) : (
          <BookOpen aria-hidden className="absolute inset-0 m-auto size-10 text-indigo-400" />
        )}
        <span className="absolute top-2 left-2 rounded bg-background/90 px-1.5 py-0.5 text-xs font-bold uppercase">
          {course.language}
        </span>
      </div>
      <div className="flex flex-1 flex-col gap-2 p-4">
        <h3 className="line-clamp-2 leading-snug font-bold">
          <Link
            href={`/courses/${course.slug}`}
            className="after:absolute after:inset-0 focus-visible:outline-none"
          >
            {course.title}
          </Link>
        </h3>
        <p className="flex items-center gap-1 text-sm text-muted-foreground">
          {course.professor.display_name}
          {course.professor.is_verified ? (
            <BadgeCheck aria-label={t("catalog.verified")} className="size-4 text-primary" />
          ) : null}
        </p>
        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
          <RatingStars rating={course.rating} count={course.rating_count} />
          <span className="text-muted-foreground">
            {t("catalog.students", { count: course.enrollment_count })}
          </span>
          {course.total_duration_seconds > 0 ? (
            <span className="text-muted-foreground">
              {formatDuration(course.total_duration_seconds, locale)}
            </span>
          ) : null}
        </div>
        <div className="mt-auto pt-2">
          <PriceTag isFree={course.is_free} price={course.price} locale={locale} />
        </div>
      </div>
    </article>
  );
}
