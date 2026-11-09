import type { ReviewResource } from "@yekkola/api-client/model";
import type { Locale } from "@yekkola/i18n";
import { getTranslations } from "next-intl/server";

import { MoreReviews } from "./more-reviews";
import { ReviewCard } from "./review-card";

/**
 * Published reviews with the professor's replies (PRD-06 FR-12; ratings shown from 3 reviews, PRD-04 BR-02): the
 * first page server-rendered, the rest on demand.
 */
export async function ReviewList({
  slug,
  reviews,
  lastPage,
  locale,
  returnTo,
}: {
  slug: string;
  reviews: ReviewResource[];
  lastPage: number;
  locale: Locale;
  /** The page, to come back to after signing in to report a review. */
  returnTo: string;
}) {
  const t = await getTranslations({ locale, namespace: "web.course" });

  if (reviews.length === 0) {
    return <p className="text-muted-foreground">{t("noReviews")}</p>;
  }

  return (
    <div className="grid gap-4">
      <ul className="grid gap-4">
        {reviews.map((review) => (
          <ReviewCard key={review.id} review={review} returnTo={returnTo} />
        ))}
      </ul>
      {lastPage > 1 ? <MoreReviews slug={slug} lastPage={lastPage} returnTo={returnTo} /> : null}
    </div>
  );
}
