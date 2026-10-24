import type { CourseCardResource } from "@yekkola/api-client/model";
import type { Locale } from "@yekkola/i18n";

import { CourseCard } from "./course-card";

/** Responsive grid: one column on phones, up to four on wide screens. */
export function CourseGrid({ courses, locale }: { courses: CourseCardResource[]; locale: Locale }) {
  return (
    <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
      {courses.map((course, index) => (
        <li key={course.id} className="flex">
          <div className="w-full">
            <CourseCard course={course} locale={locale} priority={index < 2} />
          </div>
        </li>
      ))}
    </ul>
  );
}
