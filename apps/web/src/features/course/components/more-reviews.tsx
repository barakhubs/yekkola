"use client";

import { useInfiniteQuery } from "@tanstack/react-query";
import { success } from "@yekkola/api-client";
import { coursesReviewsIndex } from "@yekkola/api-client/endpoints/review";
import { Button } from "@yekkola/ui/components/button";
import { useTranslations } from "next-intl";

import { ReviewCard } from "./review-card";

/**
 * "More reviews" under the first page the server rendered (10): loads the next pages on demand in the browser, so
 * the course page stays light.
 */
export function MoreReviews({
  slug,
  lastPage,
  returnTo,
}: {
  slug: string;
  lastPage: number;
  returnTo: string;
}) {
  const t = useTranslations("web.course");
  const errors = useTranslations("errors");
  const pages = useInfiniteQuery({
    queryKey: ["course-reviews", slug],
    initialPageParam: 2,
    enabled: false,
    queryFn: async ({ pageParam }) =>
      success(await coursesReviewsIndex(slug, { page: pageParam, per_page: 10 })),
    getNextPageParam: (last) =>
      last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined,
  });
  const loaded = pages.data?.pages.flatMap((p) => p.data) ?? [];
  const hasMore = pages.data ? pages.hasNextPage : lastPage >= 2;

  return (
    <>
      {loaded.length > 0 ? (
        <ul className="grid gap-4">
          {loaded.map((review) => (
            <ReviewCard key={review.id} review={review} returnTo={returnTo} />
          ))}
        </ul>
      ) : null}
      {pages.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errors("generic")}
        </p>
      ) : null}
      {hasMore ? (
        <Button
          variant="outline"
          className="w-fit"
          disabled={pages.isFetching}
          aria-busy={pages.isFetching}
          onClick={() => void (pages.data ? pages.fetchNextPage() : pages.refetch())}
        >
          {t("moreReviews")}
        </Button>
      ) : null}
    </>
  );
}
