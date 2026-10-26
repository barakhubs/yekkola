"use client";

import { useQuery } from "@tanstack/react-query";
import { ApiError } from "@yekkola/api-client";
import { lessonsDocument } from "@yekkola/api-client/endpoints/document";
import { EmptyState } from "@yekkola/ui/blocks/empty-state";
import { Button } from "@yekkola/ui/components/button";
import { Skeleton } from "@yekkola/ui/components/skeleton";
import { CheckCircle2, ExternalLink, FileText } from "lucide-react";
import { useTranslations } from "next-intl";
import { useEffect, useRef, useState } from "react";

interface DocumentLessonProps {
  lessonUid: string;
  title: string;
  completed: boolean;
  onDone: () => void;
}

/** Refresh the signed link this long before it expires, so "Open" never hands out a dead link. */
const REFRESH_BEFORE_EXPIRY_MS = 60_000;

/**
 * A document lesson (PRD-06 FR-06): the student's own stamped copy behind a short-lived signed link (PRD-07
 * FR-12), shown inline and openable in a new tab; done when the student says so (FR-04).
 */
export function DocumentLesson({ lessonUid, title, completed, onDone }: DocumentLessonProps) {
  const t = useTranslations("web.learn.document");
  const learn = useTranslations("web.learn");
  const common = useTranslations("common");
  const doneLine = useRef<HTMLParagraphElement>(null);
  const wasCompleted = useRef(completed);
  const [inlineSrc, setInlineSrc] = useState<string | null>(null);

  const document = useQuery({
    queryKey: ["document", lessonUid],
    queryFn: async () => {
      const data = (await lessonsDocument(lessonUid)).data.data;
      // The inline viewer keeps its first link: a refreshed one would reload the PDF at page 1.
      if (data.status === "ready") setInlineSrc((current) => current ?? data.url);
      return data;
    },
    // Made on the queue: ask again until ready, then keep the link fresh until shortly before it expires.
    refetchInterval: (query) => {
      const data = query.state.data;
      if (!data) return false;
      if (data.status !== "ready") return (("retry_after" in data ? data.retry_after : 3) || 3) * 1000;
      return Math.max(10_000, new Date(data.expires_at).getTime() - Date.now() - REFRESH_BEFORE_EXPIRY_MS);
    },
    refetchOnWindowFocus: false,
    retry: (failures, error) => !(error instanceof ApiError && error.status < 500) && failures < 2,
  });

  const ready = document.data?.status === "ready" ? document.data : null;

  // Keyboard and screen-reader users land on the confirmation, not the top of the page.
  useEffect(() => {
    if (completed && !wasCompleted.current) doneLine.current?.focus();
    wasCompleted.current = completed;
  }, [completed]);

  if (document.isError) {
    return (
      <EmptyState
        title={
          document.error instanceof ApiError
            ? (document.error.title ?? learn("lessonError"))
            : learn("lessonError")
        }
        action={
          <Button variant="outline" onClick={() => document.refetch()}>
            {common("retry")}
          </Button>
        }
      />
    );
  }

  return (
    <div className="grid gap-4">
      {ready ? (
        <>
          {inlineSrc ? (
            <iframe
              src={inlineSrc}
              title={title}
              className="hidden h-[70vh] w-full rounded-md border bg-white md:block"
            />
          ) : null}
          <div className="flex flex-wrap items-center gap-3">
            <Button asChild variant="outline">
              <a href={ready.url} target="_blank" rel="noopener noreferrer">
                <ExternalLink aria-hidden />
                {t("open")}
              </a>
            </Button>
          </div>
        </>
      ) : (
        <div
          className="grid justify-items-center gap-3 rounded-md border border-dashed p-10 text-center"
          role="status"
        >
          <FileText aria-hidden className="size-8 text-muted-foreground" />
          <p>{t("preparing")}</p>
          <Skeleton className="h-2 w-40" />
        </div>
      )}
      <p className="text-sm text-muted-foreground">{t("lowData")}</p>
      <div>
        {completed ? (
          <p
            ref={doneLine}
            tabIndex={-1}
            className="inline-flex items-center gap-2 font-semibold text-success focus:outline-none"
          >
            <CheckCircle2 aria-hidden className="size-5" />
            {t("done")}
          </p>
        ) : (
          <Button onClick={onDone} disabled={!ready}>
            {t("markDone")}
          </Button>
        )}
      </div>
    </div>
  );
}
