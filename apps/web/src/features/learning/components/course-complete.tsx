"use client";

import { useMutation } from "@tanstack/react-query";
import { ApiError, success } from "@yekkola/api-client";
import { coursesReviewStore } from "@yekkola/api-client/endpoints/review";
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
      {hasReviewed ? null : <ReviewForm courseId={courseId} />}
    </section>
  );
}

function ReviewForm({ courseId }: { courseId: string }) {
  const t = useTranslations("web.learn.complete");
  const errors = useTranslations("errors");
  const [rating, setRating] = useState(0);
  const [body, setBody] = useState("");

  const post = useMutation({
    mutationFn: async () =>
      success(await coursesReviewStore(courseId, { rating, body: body.trim() || null })),
  });

  if (post.isSuccess) {
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
        <h3 className="font-bold">{t("reviewTitle")}</h3>
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
          {t("send")}
        </Button>
      </div>
    </form>
  );
}
