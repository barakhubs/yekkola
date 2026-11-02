"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { success } from "@yekkola/api-client";
import {
  adminReviewsHide,
  adminReviewsRestore,
  getAdminReviewsIndexQueryKey,
  useAdminReviewsIndex,
} from "@yekkola/api-client/endpoints/review-moderation";
import { formatDateTime, type Locale } from "@yekkola/i18n";
import { ConfirmDialog } from "@yekkola/ui/blocks/confirm-dialog";
import { PageHeader } from "@yekkola/ui/blocks/page-header";
import { Badge } from "@yekkola/ui/components/badge";
import { Button } from "@yekkola/ui/components/button";
import { Input } from "@yekkola/ui/components/input";
import { Label } from "@yekkola/ui/components/label";
import { Star } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { parseAsInteger, parseAsString, useQueryState } from "nuqs";
import { type FormEvent, useState } from "react";

import { useErrorText } from "@/features/common/errors";
import { selectClass } from "@/features/common/styles";
import { useTableLabels } from "@/features/common/table";

/**
 * Review moderation (PRD-09 FR-10): every review, newest first; hide one that breaks the rules (it leaves the course
 * page and the ratings at once) or restore it, each with a reason in the audit log.
 */
export function ReviewModeration() {
  const t = useTranslations("admin.reviewModeration");
  const common = useTranslations("common");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const labels = useTableLabels();
  const queryClient = useQueryClient();
  const [status, setStatus] = useQueryState("status", parseAsString.withDefault("all"));
  const [rating, setRating] = useQueryState("rating", parseAsString.withDefault("all"));
  const [search, setSearch] = useQueryState("q", parseAsString.withDefault(""));
  const [page, setPage] = useQueryState("page", parseAsInteger.withDefault(1));
  const [draft, setDraft] = useState(search);

  const reviews = useAdminReviewsIndex(
    {
      page,
      per_page: 20,
      ...(status === "all" ? {} : { "filter[status]": status }),
      ...(rating === "all" ? {} : { "filter[rating]": Number(rating) }),
      ...(search ? { "filter[q]": search } : {}),
    },
    { query: { select: (r) => success(r), placeholderData: (previous) => previous } },
  );
  const act = useMutation({
    mutationFn: ({ id, hide, note }: { id: string; hide: boolean; note: string }) =>
      hide ? adminReviewsHide(id, { note }) : adminReviewsRestore(id, { note }),
    onSettled: () => queryClient.invalidateQueries({ queryKey: getAdminReviewsIndexQueryKey() }),
  });
  const lastPage = reviews.data?.meta.last_page ?? 1;

  return (
    <div className="mx-auto grid max-w-5xl gap-6 px-4 py-8 sm:px-6">
      <PageHeader title={t("title")} description={t("intro")} />

      <div className="grid gap-4 sm:grid-cols-3">
        <form
          role="search"
          className="grid gap-2"
          onSubmit={(e: FormEvent) => {
            e.preventDefault();
            void setSearch(draft.trim() || null);
            void setPage(1);
          }}
        >
          <Label htmlFor="review-search">{t("search")}</Label>
          <Input id="review-search" type="search" value={draft} onChange={(e) => setDraft(e.target.value)} />
        </form>
        <div className="grid gap-2">
          <Label htmlFor="review-status">{t("status")}</Label>
          <select
            id="review-status"
            className={selectClass}
            value={status}
            onChange={(e) => {
              void setStatus(e.target.value);
              void setPage(1);
            }}
          >
            <option value="all">{t("all")}</option>
            <option value="visible">{t("visible")}</option>
            <option value="hidden">{t("hidden")}</option>
          </select>
        </div>
        <div className="grid gap-2">
          <Label htmlFor="review-rating">{t("rating")}</Label>
          <select
            id="review-rating"
            className={selectClass}
            value={rating}
            onChange={(e) => {
              void setRating(e.target.value);
              void setPage(1);
            }}
          >
            <option value="all">{t("all")}</option>
            {[1, 2, 3, 4, 5].map((n) => (
              <option key={n} value={n}>
                {t("stars", { count: n })}
              </option>
            ))}
          </select>
        </div>
      </div>

      {reviews.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(reviews.error)}
        </p>
      ) : null}
      {reviews.data?.data.length === 0 ? <p className="text-sm text-muted-foreground">{t("empty")}</p> : null}

      <ul className="grid gap-3" aria-busy={reviews.isFetching}>
        {(reviews.data?.data ?? []).map((r) => (
          <li key={r.id} className="grid gap-2 rounded-xl border bg-background p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <span className="flex items-center gap-2 font-semibold">
                <span
                  className="flex items-center gap-0.5 text-amber-700"
                  aria-label={t("stars", { count: r.rating })}
                >
                  {Array.from({ length: r.rating }, (_, i) => (
                    <Star key={i} aria-hidden className="size-4 fill-current" />
                  ))}
                </span>
                {r.author.name ?? "—"} · {r.course.title ?? r.course.slug}
              </span>
              <span className="flex items-center gap-2 text-xs text-muted-foreground">
                {r.status === "hidden" ? <Badge variant="destructive">{t("hidden")}</Badge> : null}
                {formatDateTime(r.created_at, locale)}
              </span>
            </div>
            {r.body ? <p className="text-sm whitespace-pre-line">{r.body}</p> : null}
            {r.hidden_reason ? (
              <p className="text-xs text-muted-foreground">
                {t("hiddenBecause", { reason: r.hidden_reason })}
              </p>
            ) : null}
            <div className="flex justify-end">
              {r.can.hide || r.can.restore ? (
                <ConfirmDialog
                  trigger={
                    <Button size="sm" variant={r.can.hide ? "destructive" : "outline"}>
                      {r.can.hide ? t("hide") : t("restore")}
                    </Button>
                  }
                  title={r.can.hide ? t("hideConfirm") : t("restoreConfirm")}
                  description={r.can.hide ? t("hideHint") : t("restoreHint")}
                  reason={{ label: t("reason"), minLength: 3 }}
                  confirmLabel={r.can.hide ? t("hide") : t("restore")}
                  cancelLabel={common("cancel")}
                  destructive={r.can.hide}
                  onConfirm={async (note) => {
                    await act.mutateAsync({ id: r.id, hide: r.can.hide, note });
                  }}
                  error={act.isError && act.variables?.id === r.id ? errorText(act.error) : undefined}
                  onOpenChange={(open) => {
                    if (open) act.reset();
                  }}
                />
              ) : null}
            </div>
          </li>
        ))}
      </ul>

      {lastPage > 1 ? (
        <nav aria-label={labels.pagination} className="flex items-center justify-between gap-4">
          <Button variant="outline" disabled={page <= 1} onClick={() => void setPage(page - 1)}>
            {labels.previous}
          </Button>
          <span className="text-sm text-muted-foreground">{labels.page(page, lastPage)}</span>
          <Button variant="outline" disabled={page >= lastPage} onClick={() => void setPage(page + 1)}>
            {labels.next}
          </Button>
        </nav>
      ) : null}
    </div>
  );
}
