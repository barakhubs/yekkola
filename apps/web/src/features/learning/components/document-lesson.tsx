"use client";

import { useQuery } from "@tanstack/react-query";
import { ApiError } from "@yekkola/api-client";
import { lessonsDocument } from "@yekkola/api-client/endpoints/document";
import { EmptyState } from "@yekkola/ui/blocks/empty-state";
import { Button } from "@yekkola/ui/components/button";
import { Skeleton } from "@yekkola/ui/components/skeleton";
import { CheckCircle2, ExternalLink, FileText } from "lucide-react";
import { useTranslations } from "next-intl";

interface DocumentLessonProps {
  lessonUid: string;
  title: string;
  completed: boolean;
  onDone: () => void;
}

/**
 * A document lesson (PRD-06 FR-06): the student's own stamped copy behind a short-lived signed link (PRD-07
 * FR-12), shown inline and openable in a new tab; done when the student says so (FR-04).
 */
export function DocumentLesson({ lessonUid, title, completed, onDone }: DocumentLessonProps) {
  const t = useTranslations("web.learn.document");
  const learn = useTranslations("web.learn");
  const common = useTranslations("common");

  const document = useQuery({
    queryKey: ["document", lessonUid],
    queryFn: async () => {
      const response = await lessonsDocument(lessonUid);
      return response.data.data;
    },
    // The copy is made on the queue: ask again until it's ready.
    refetchInterval: (query) => {
      const data = query.state.data;
      return data && data.status !== "ready"
        ? (("retry_after" in data ? data.retry_after : 3) || 3) * 1000
        : false;
    },
    staleTime: 5 * 60_000, // links last a few minutes
    gcTime: 5 * 60_000,
    retry: (failures, error) => !(error instanceof ApiError && error.status < 500) && failures < 2,
  });

  if (document.isError) {
    return (
      <EmptyState
        title={
          document.error instanceof ApiError
            ? (document.error.body.message ?? learn("lessonError"))
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

  const ready = document.data?.status === "ready" ? document.data : null;

  return (
    <div className="grid gap-4">
      {ready ? (
        <>
          <iframe
            src={ready.url}
            title={title}
            className="hidden h-[70vh] w-full rounded-md border bg-white md:block"
          />
          <div className="flex flex-wrap items-center gap-3">
            <Button asChild variant="outline">
              <a href={ready.url} target="_blank" rel="noopener noreferrer">
                <ExternalLink aria-hidden />
                {t("open")}
              </a>
            </Button>
            <p className="text-xs text-muted-foreground">{t("linkExpires")}</p>
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
          <p className="inline-flex items-center gap-2 font-semibold text-success">
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
