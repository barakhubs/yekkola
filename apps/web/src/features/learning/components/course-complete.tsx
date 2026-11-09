"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { ApiError, success } from "@yekkola/api-client";
import {
  coursesReviewDestroy,
  coursesReviewStore,
  coursesReviewUpdate,
  getCoursesReviewShowQueryKey,
  useCoursesReviewShow,
} from "@yekkola/api-client/endpoints/review";
import type { ReviewResource } from "@yekkola/api-client/model";
import { ConfirmDialog } from "@yekkola/ui/blocks/confirm-dialog";
import { Button } from "@yekkola/ui/components/button";
import { Label } from "@yekkola/ui/components/label";
import { Textarea } from "@yekkola/ui/components/textarea";
import { cn } from "@yekkola/ui/lib/utils";
import { Award, Star } from "lucide-react";
import { useTranslations } from "next-intl";
import { type FormEvent, useState } from "react";

import { Link } from "@/i18n/navigation";

interface CourseCompleteProps {
  courseId: string;
  certificateSerial: string | null;
  hasReviewed: boolean;
}

/** Completion (PRD-06 FR-16): the certificate (issued in the background) and a review prompt. */
export function CourseComplete({ courseId, certificateSerial, hasReviewed }: CourseCompleteProps) {
  const t = useTranslations("web.learn.complete");

  return (
    <section
      aria-labelledby="course-complete"
      className="grid gap-4 rounded-xl border-2 border-brand-accent bg-amber-50 p-5 dark:bg-indigo-950"
    >
      <h2
        id="course-complete"
        className="flex items-center gap-2 text-lg font-extrabold text-indigo-900 dark:text-indigo-100"
      >
        <Award aria-hidden className="size-6 text-amber-700 dark:text-amber-300" />
        {t("title")}
      </h2>
      {certificateSerial ? (
        <div>
          <Button asChild>
            <Link href={`/certificates/${certificateSerial}`}>{t("certificate")}</Link>
          </Button>
        </div>
      ) : (
        <p className="text-sm text-muted-foreground">{t("certificatePending")}</p>
      )}
      {hasReviewed ? <MyReview courseId={courseId} /> : <ReviewForm courseId={courseId} />}
    </section>
  );
}

/** My published review (PRD-06 FR-12): shown with edit and delete; editing reuses the form. */
function MyReview({ courseId }: { courseId: string }) {
  const t = useTranslations("web.learn.complete");
  const common = useTranslations("common");
  const errors = useTranslations("errors");
  const queryClient = useQueryClient();
  const [editing, setEditing] = useState(false);
  const review = useCoursesReviewShow(courseId, { query: { select: (r) => success(r).data, retry: false } });

  const remove = useMutation({
    mutationFn: async () => {
      await coursesReviewDestroy(courseId);
    },
    onSuccess: () => queryClient.removeQueries({ queryKey: getCoursesReviewShowQueryKey(courseId) }),
  });

  if (remove.isSuccess) return <ReviewForm courseId={courseId} />;
  if (review.isPending) return null;
  // Not found (deleted elsewhere): offer to write one again. Any other error is shown as such.
  if (review.isError) {
    if (review.error instanceof ApiError && review.error.status === 404)
      return <ReviewForm courseId={courseId} />;
    return (
      <p role="alert" className="text-sm text-destructive">
        {review.error instanceof ApiError ? (review.error.title ?? errors("generic")) : errors("network")}
      </p>
    );
  }
  if (editing) {
    return <ReviewForm courseId={courseId} existing={review.data} onDone={() => setEditing(false)} />;
  }

  return (
    <div className="grid gap-2">
      <h3 className="font-bold">{t("yourReview")}</h3>
      <p className="flex gap-0.5" aria-label={t("stars", { count: review.data.rating })}>
        {[1, 2, 3, 4, 5].map((n) => (
          <Star
            key={n}
            aria-hidden
            className={cn(
              "size-5",
              n <= review.data.rating ? "fill-amber-300 stroke-amber-500" : "stroke-muted-foreground",
            )}
          />
        ))}
      </p>
      {review.data.body ? <p className="text-sm whitespace-pre-line">{review.data.body}</p> : null}
      {review.data.status && review.data.status !== "published" ? (
        <p className="text-xs text-muted-foreground">{t("reviewHidden")}</p>
      ) : null}
      <div className="flex flex-wrap gap-2">
        <Button variant="outline" size="sm" onClick={() => setEditing(true)}>
          {t("editReview")}
        </Button>
        <ConfirmDialog
          trigger={
            <Button variant="ghost" size="sm">
              {t("deleteReview")}
            </Button>
          }
          title={t("deleteReviewTitle")}
          description={t("deleteReviewHint")}
          confirmLabel={t("deleteReview")}
          cancelLabel={common("cancel")}
          destructive
          onConfirm={async () => {
            await remove.mutateAsync();
          }}
          error={
            remove.isError
              ? remove.error instanceof ApiError
                ? (remove.error.title ?? errors("generic"))
                : errors("network")
              : undefined
          }
          onOpenChange={(open) => open && remove.reset()}
        />
      </div>
    </div>
  );
}

function ReviewForm({
  courseId,
  existing,
  onDone,
}: {
  courseId: string;
  existing?: ReviewResource;
  onDone?: () => void;
}) {
  const t = useTranslations("web.learn.complete");
  const errors = useTranslations("errors");
  const queryClient = useQueryClient();
  const [rating, setRating] = useState(existing?.rating ?? 0);
  const [body, setBody] = useState(existing?.body ?? "");

  const post = useMutation({
    mutationFn: async () => {
      const data = { rating, body: body.trim() || null };
      return success(
        existing ? await coursesReviewUpdate(courseId, data) : await coursesReviewStore(courseId, data),
      );
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: getCoursesReviewShowQueryKey(courseId) });
      onDone?.();
    },
  });

  if (post.isSuccess && !existing) {
    return (
      <p ref={(el) => el?.focus()} tabIndex={-1} role="status" className="font-semibold focus:outline-none">
        {t("thanks")}
      </p>
    );
  }

  return (
    <form
      className="grid gap-3"
      onSubmit={(e: FormEvent) => {
        e.preventDefault();
        if (rating > 0) post.mutate();
      }}
    >
      <div className="grid gap-1">
        <h3 className="font-bold">{existing ? t("editReviewTitle") : t("reviewTitle")}</h3>
        <p className="text-sm text-muted-foreground">{t("reviewHint")}</p>
      </div>
      <fieldset className="grid gap-1">
        <legend className="text-sm font-semibold">{t("rating")}</legend>
        <div className="flex gap-1">
          {[1, 2, 3, 4, 5].map((n) => (
            <label
              key={n}
              className="cursor-pointer rounded p-1 has-focus-visible:ring-2 has-focus-visible:ring-ring"
            >
              <input
                type="radio"
                name="rating"
                value={n}
                className="sr-only"
                checked={rating === n}
                onChange={() => setRating(n)}
              />
              <Star
                aria-hidden
                className={cn(
                  "size-7",
                  n <= rating ? "fill-amber-300 stroke-amber-500" : "stroke-muted-foreground",
                )}
              />
              <span className="sr-only">{t("stars", { count: n })}</span>
            </label>
          ))}
        </div>
      </fieldset>
      <div className="grid gap-1">
        <Label htmlFor="review-body">{t("comment")}</Label>
        <Textarea id="review-body" value={body} maxLength={2000} onChange={(e) => setBody(e.target.value)} />
      </div>
      {post.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {post.error instanceof ApiError ? (post.error.title ?? errors("generic")) : errors("network")}
        </p>
      ) : null}
      <div>
        <Button type="submit" disabled={rating === 0 || post.isPending} aria-busy={post.isPending}>
          {existing ? t("saveReview") : t("send")}
        </Button>
        {existing ? (
          <Button type="button" variant="ghost" className="ml-2" onClick={onDone}>
            {t("cancelEdit")}
          </Button>
        ) : null}
      </div>
    </form>
  );
}
