"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { success } from "@yekkola/api-client";
import { useProfessorCoursesIndex } from "@yekkola/api-client/endpoints/course";
import {
  getProfessorReviewsIndexQueryKey,
  professorReviewsReply,
  useProfessorReviewsIndex,
} from "@yekkola/api-client/endpoints/review";
import type { ReviewResource } from "@yekkola/api-client/model";
import { formatDate, type Locale } from "@yekkola/i18n";
import { Button } from "@yekkola/ui/components/button";
import { Label } from "@yekkola/ui/components/label";
import { Skeleton } from "@yekkola/ui/components/skeleton";
import { Textarea } from "@yekkola/ui/components/textarea";
import { cn } from "@yekkola/ui/lib/utils";
import { Star } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { parseAsInteger, parseAsString, parseAsStringLiteral, useQueryState } from "nuqs";
import { type FormEvent, useState } from "react";

import { useErrorText } from "../hooks";
import { selectClass } from "../styles";

const REPLIED = ["all", "false", "true"] as const;
const RATINGS = ["all", "5", "4", "3", "2", "1"] as const;

/**
 * Reviews of my courses (PRD-06 FR-12, US-9): newest first, filter by course, rating or whether I replied; write,
 * edit or remove my public reply. Reviews hidden by moderation are marked (still visible here, not to students).
 */
export function ReviewsInbox() {
  const t = useTranslations("web.studioReviews");
  const errorText = useErrorText();
  const [course, setCourse] = useQueryState("course", parseAsString.withDefault(""));
  const [rating, setRating] = useQueryState("rating", parseAsStringLiteral(RATINGS).withDefault("all"));
  const [replied, setReplied] = useQueryState("replied", parseAsStringLiteral(REPLIED).withDefault("false"));
  const [page, setPage] = useQueryState("page", parseAsInteger.withDefault(1));

  const courses = useProfessorCoursesIndex(
    { per_page: 100 },
    { query: { select: (r) => success(r).data.filter((c) => c.live_version !== null) } },
  );
  const reviews = useProfessorReviewsIndex(
    {
      page,
      per_page: 20,
      ...(course ? { "filter[course_id]": course } : {}),
      ...(rating === "all" ? {} : { "filter[rating]": Number(rating) }),
      ...(replied === "all" ? {} : { "filter[replied]": replied }),
    },
    { query: { select: (r) => success(r), placeholderData: (previous) => previous } },
  );
  const lastPage = reviews.data?.meta.last_page ?? 1;

  const filter = (
    id: string,
    label: string,
    value: string,
    onChange: (v: string) => void,
    options: { value: string; label: string }[],
  ) => (
    <div className="grid gap-1.5">
      <Label htmlFor={id}>{label}</Label>
      <select
        id={id}
        className={selectClass}
        value={value}
        onChange={(e) => {
          onChange(e.target.value);
          void setPage(1);
        }}
      >
        {options.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </select>
    </div>
  );

  return (
    <div className="mx-auto grid max-w-4xl gap-6 px-4 py-8">
      <header className="grid gap-1">
        <h1 className="text-2xl font-extrabold">{t("title")}</h1>
        <p className="text-muted-foreground">{t("intro")}</p>
      </header>

      <div className="grid gap-3 sm:grid-cols-3">
        {filter(
          "reviews-replied",
          t("repliedFilter"),
          replied,
          (v) => void setReplied(v as (typeof REPLIED)[number]),
          [
            { value: "false", label: t("replied.false") },
            { value: "true", label: t("replied.true") },
            { value: "all", label: t("replied.all") },
          ],
        )}
        {filter(
          "reviews-rating",
          t("ratingFilter"),
          rating,
          (v) => void setRating(v as (typeof RATINGS)[number]),
          [
            { value: "all", label: t("allRatings") },
            ...RATINGS.filter((r) => r !== "all").map((r) => ({
              value: r,
              label: t("stars", { count: Number(r) }),
            })),
          ],
        )}
        {filter("reviews-course", t("courseFilter"), course, (v) => void setCourse(v || null), [
          { value: "", label: t("allCourses") },
          ...(courses.data ?? []).map((c) => ({ value: c.id, label: c.title ?? c.slug })),
        ])}
      </div>

      {reviews.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(reviews.error)}
        </p>
      ) : reviews.isPending ? (
        <Skeleton className="h-40 w-full" />
      ) : reviews.data.data.length === 0 ? (
        <p className="text-sm text-muted-foreground">{replied === "false" ? t("allReplied") : t("empty")}</p>
      ) : (
        <ul className="grid gap-3" aria-busy={reviews.isFetching}>
          {reviews.data.data.map((r) => (
            <ReviewItem key={r.id} review={r} />
          ))}
        </ul>
      )}

      {lastPage > 1 ? (
        <nav className="flex items-center justify-between gap-2" aria-label={t("title")}>
          <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => void setPage(page - 1)}>
            {t("previous")}
          </Button>
          <span className="text-sm text-muted-foreground">{t("pageOf", { page, total: lastPage })}</span>
          <Button
            variant="outline"
            size="sm"
            disabled={page >= lastPage}
            onClick={() => void setPage(page + 1)}
          >
            {t("next")}
          </Button>
        </nav>
      ) : null}
    </div>
  );
}

function ReviewItem({ review }: { review: ReviewResource }) {
  const t = useTranslations("web.studioReviews");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const queryClient = useQueryClient();
  const [editing, setEditing] = useState(false);
  const [text, setText] = useState(review.reply?.body ?? "");

  const save = useMutation({
    mutationFn: async (reply: string | null) => {
      await professorReviewsReply(review.id, { reply });
    },
    onSuccess: async () => {
      setEditing(false);
      await queryClient.invalidateQueries({ queryKey: getProfessorReviewsIndexQueryKey() });
    },
  });

  return (
    <li className="grid gap-3 rounded-xl border p-4">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="grid gap-0.5">
          <span className="flex gap-0.5" aria-label={t("stars", { count: review.rating })}>
            {[1, 2, 3, 4, 5].map((n) => (
              <Star
                key={n}
                aria-hidden
                className={cn(
                  "size-4",
                  n <= review.rating ? "fill-amber-300 stroke-amber-500" : "stroke-muted-foreground",
                )}
              />
            ))}
          </span>
          <span className="text-sm text-muted-foreground">
            {review.author.name ?? t("student")} · {formatDate(review.created_at, locale)}
            {review.course?.title ? ` · ${review.course.title}` : ""}
          </span>
        </div>
        {review.status === "hidden" ? (
          <span className="rounded-md bg-muted px-2 py-0.5 text-xs font-semibold">{t("hidden")}</span>
        ) : null}
      </div>
      {review.body ? <p className="text-sm whitespace-pre-line">{review.body}</p> : null}

      {review.reply && !editing ? (
        <div className="grid gap-2 rounded-md border-l-4 border-primary bg-muted/40 p-3">
          <span className="text-xs font-semibold text-muted-foreground">
            {t("yourReply")}
            {review.reply.replied_at ? ` · ${formatDate(review.reply.replied_at, locale)}` : ""}
          </span>
          <p className="text-sm whitespace-pre-line">{review.reply.body}</p>
          <div className="flex gap-2">
            <Button
              size="sm"
              variant="outline"
              onClick={() => {
                setText(review.reply?.body ?? "");
                setEditing(true);
              }}
            >
              {t("editReply")}
            </Button>
            <Button size="sm" variant="ghost" disabled={save.isPending} onClick={() => save.mutate(null)}>
              {t("removeReply")}
            </Button>
          </div>
        </div>
      ) : (
        <form
          className="grid gap-2"
          onSubmit={(e: FormEvent) => {
            e.preventDefault();
            if (text.trim() && !save.isPending) save.mutate(text.trim());
          }}
        >
          <Label htmlFor={`reply-${review.id}`}>{t("replyLabel")}</Label>
          <Textarea
            id={`reply-${review.id}`}
            rows={3}
            maxLength={2000}
            value={text}
            onChange={(e) => setText(e.target.value)}
          />
          <p className="text-xs text-muted-foreground">{t("replyHint")}</p>
          <div className="flex gap-2">
            <Button
              type="submit"
              size="sm"
              disabled={!text.trim() || save.isPending}
              aria-busy={save.isPending}
            >
              {review.reply ? t("saveReply") : t("sendReply")}
            </Button>
            {editing ? (
              <Button type="button" size="sm" variant="ghost" onClick={() => setEditing(false)}>
                {t("cancel")}
              </Button>
            ) : null}
          </div>
        </form>
      )}
      {save.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(save.error)}
        </p>
      ) : null}
    </li>
  );
}
