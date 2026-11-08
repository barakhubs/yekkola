"use client";

import { success } from "@yekkola/api-client";
import { useProfessorThreadsIndex } from "@yekkola/api-client/endpoints/thread";
import { Button } from "@yekkola/ui/components/button";
import { Label } from "@yekkola/ui/components/label";
import { useTranslations } from "next-intl";
import { parseAsInteger, parseAsStringLiteral, useQueryState } from "nuqs";
import { useState } from "react";

import { ThreadDetail, ThreadHeader } from "@/features/learning/components/lesson-qa";
import { useErrorText } from "@/features/studio/hooks";

const STATUSES = ["open", "answered", "all"] as const;

/**
 * Q&A inbox (PRD-06 FR-13): questions on my courses, unanswered and oldest first by default, so none waits too
 * long. Opening one shows the replies and lets me answer (my reply marks it answered).
 */
export function QaInbox() {
  const t = useTranslations("web.qa");
  const errorText = useErrorText();
  const [status, setStatus] = useQueryState("status", parseAsStringLiteral(STATUSES).withDefault("open"));
  const [page, setPage] = useQueryState("page", parseAsInteger.withDefault(1));
  const [openId, setOpenId] = useState<string | null>(null);
  const threads = useProfessorThreadsIndex(
    { page, per_page: 20, "filter[status]": status },
    { query: { select: (r) => success(r), placeholderData: (previous) => previous } },
  );
  const lastPage = threads.data?.meta.last_page ?? 1;

  return (
    <div className="mx-auto grid max-w-4xl gap-6 px-4 py-8">
      <header className="grid gap-1">
        <h1 className="text-2xl font-extrabold">{t("inboxTitle")}</h1>
        <p className="text-muted-foreground">{t("inboxIntro")}</p>
      </header>

      <div className="grid max-w-xs gap-2">
        <Label htmlFor="qa-status">{t("statusFilter")}</Label>
        <select
          id="qa-status"
          className="h-10 rounded-md border bg-background px-3 text-sm"
          value={status}
          onChange={(e) => {
            void setStatus(e.target.value as (typeof STATUSES)[number]);
            void setPage(1);
          }}
        >
          {STATUSES.map((s) => (
            <option key={s} value={s}>
              {t(`statuses.${s}`)}
            </option>
          ))}
        </select>
      </div>

      {threads.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(threads.error)}
        </p>
      ) : null}
      {threads.data?.data.length === 0 ? <p className="text-muted-foreground">{t("inboxEmpty")}</p> : null}

      <ul className="grid gap-3" aria-busy={threads.isFetching}>
        {(threads.data?.data ?? []).map((thread) => (
          <li key={thread.id} className="rounded-lg border bg-background">
            <button
              type="button"
              className="grid w-full gap-1 p-3 text-left hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
              aria-expanded={openId === thread.id}
              onClick={() => setOpenId(openId === thread.id ? null : thread.id)}
            >
              <span className="text-xs font-semibold text-primary">
                {thread.course?.title ?? ""}
                {thread.lesson_title ? ` · ${thread.lesson_title}` : ""}
              </span>
              <ThreadHeader thread={thread} />
            </button>
            {openId === thread.id ? (
              <ThreadDetail threadId={thread.id} returnTo={`/studio/questions`} />
            ) : null}
          </li>
        ))}
      </ul>

      {lastPage > 1 ? (
        <nav aria-label={t("pages")} className="flex items-center justify-between gap-3">
          <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => void setPage(page - 1)}>
            {t("previous")}
          </Button>
          <span className="text-sm text-muted-foreground">{t("page", { page, last: lastPage })}</span>
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
