import { Star } from "lucide-react";
import { getFormatter, getTranslations } from "next-intl/server";

interface RatingStarsProps {
  /** Null until the course has enough reviews (PRD-04 BR-02): shows "Nouveau". */
  rating: number | null;
  count: number;
  /** On a dark band (course hero): light text for contrast. */
  onDark?: boolean;
}

export async function RatingStars({ rating, count, onDark = false }: RatingStarsProps) {
  const t = await getTranslations("web");
  const format = await getFormatter();

  if (rating === null) {
    return (
      <span
        className={
          onDark
            ? "text-xs font-semibold text-amber-300"
            : "text-xs font-semibold text-amber-700 dark:text-amber-300"
        }
      >
        {t("course.newCourse")}
      </span>
    );
  }

  return (
    <span
      className="inline-flex items-center gap-1 text-sm"
      aria-label={t("catalog.rating", { rating, count })}
    >
      <Star aria-hidden className="size-4 fill-amber-300 stroke-amber-500" />
      <span className="font-bold tabular-nums">
        {format.number(rating, { minimumFractionDigits: 1, maximumFractionDigits: 1 })}
      </span>
      <span className={onDark ? "text-indigo-100 tabular-nums" : "text-muted-foreground tabular-nums"}>
        ({count})
      </span>
    </span>
  );
}
