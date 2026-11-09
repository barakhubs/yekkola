"use client";

import type { ReviewResource } from "@yekkola/api-client/model";
import { formatDate, type Locale } from "@yekkola/i18n";
import { Star } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";

import { ReportButton } from "@/features/moderation/report-button";

/** One published review with the professor's reply (PRD-06 FR-12). Shared by the first page and "more reviews". */
export function ReviewCard({ review, returnTo }: { review: ReviewResource; returnTo: string }) {
  const t = useTranslations("web.course");
  const locale = useLocale() as Locale;

  return (
    <li className="grid gap-2 rounded-lg border p-4">
      <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
        <span className="font-bold">{review.author.name ?? t("anonymous")}</span>
        <span className="inline-flex" role="img" aria-label={t("ratingOutOf5", { rating: review.rating })}>
          {[1, 2, 3, 4, 5].map((n) => (
            <Star
              key={n}
              aria-hidden
              className={
                n <= review.rating
                  ? "size-4 fill-amber-300 stroke-amber-500"
                  : "size-4 stroke-muted-foreground"
              }
            />
          ))}
        </span>
        <time dateTime={review.created_at} className="text-muted-foreground">
          {formatDate(review.created_at, locale)}
        </time>
        {review.edited_at ? <span className="text-xs text-muted-foreground">({t("edited")})</span> : null}
      </div>
      {review.body ? <p className="whitespace-pre-line">{review.body}</p> : null}
      {review.reply ? (
        <div className="rounded-md border-l-4 border-primary bg-accent px-3 py-2 text-sm">
          <p className="font-bold text-accent-foreground">{t("professorReply")}</p>
          <p className="whitespace-pre-line">{review.reply.body}</p>
        </div>
      ) : null}
      <div className="flex justify-end">
        <ReportButton type="review" id={review.id} returnTo={returnTo} />
      </div>
    </li>
  );
}
