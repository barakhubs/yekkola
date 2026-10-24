import type { CourseCardResource } from "@yekkola/api-client/model";
import type { Locale } from "@yekkola/i18n";
import { getTranslations } from "next-intl/server";

import { Link } from "@/i18n/navigation";

import { CourseCard } from "./course-card";

interface CourseRailProps {
  /** Stable id for the heading (aria-labelledby). */
  id: string;
  title: string;
  courses: CourseCardResource[];
  locale: Locale;
  /** Catalogue link for "See all" (e.g. `/courses?price=free`). */
  href?: string;
  priority?: boolean;
}

/** A horizontal row of course cards; swipes on phones, wraps into a grid on wide screens. */
export async function CourseRail({ id, title, courses, locale, href, priority = false }: CourseRailProps) {
  if (courses.length === 0) {
    return null;
  }
  const t = await getTranslations("web.catalog");
  const headingId = `rail-${id}`;

  return (
    <section aria-labelledby={headingId} className="grid gap-4">
      <div className="flex items-end justify-between gap-4">
        <h2 id={headingId} className="text-xl font-bold sm:text-2xl">
          {title}
        </h2>
        {href ? (
          <Link href={href} className="text-sm font-bold text-primary underline-offset-4 hover:underline">
            {t("seeAll")}
          </Link>
        ) : null}
      </div>
      <ul className="-mx-4 flex snap-x snap-mandatory gap-4 overflow-x-auto px-4 pb-2 lg:mx-0 lg:grid lg:grid-cols-4 lg:overflow-visible lg:px-0">
        {courses.map((course, index) => (
          <li key={course.id} className="w-72 shrink-0 snap-start lg:w-auto">
            <CourseCard course={course} locale={locale} priority={priority && index < 2} />
          </li>
        ))}
      </ul>
    </section>
  );
}
