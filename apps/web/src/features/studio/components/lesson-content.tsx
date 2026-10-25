"use client";

import { useMutation } from "@tanstack/react-query";
import { success } from "@yekkola/api-client";
import { professorDraftLessonsDocument } from "@yekkola/api-client/endpoints/draft";
import { useProfessorMediaShow } from "@yekkola/api-client/endpoints/media";
import type { StudioLessonResource } from "@yekkola/api-client/model";
import { formatBytes, formatDuration, type Locale } from "@yekkola/i18n";
import { Button } from "@yekkola/ui/components/button";
import { Input } from "@yekkola/ui/components/input";
import { Label } from "@yekkola/ui/components/label";
import { CheckCircle2, FileText, Loader2, TriangleAlert, WifiOff } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useEffect, useId, useState } from "react";

import { fieldErrorsOf, useAuthoringOptions, useErrorText, useRefreshCourse } from "../hooks";
import { cancelUpload, clearUpload, startUpload, useUpload } from "../upload-store";

const MB = 1024 * 1024;

/**
 * Video or audio file for a lesson (PRD-03 FR-04): upload straight to the provider with progress, then the
 * processing status until the file is ready (or failed, with a way to upload again).
 */
export function MediaContent({
  courseId,
  lesson,
  editable,
}: {
  courseId: string;
  lesson: StudioLessonResource;
  editable: boolean;
}) {
  const t = useTranslations("web.studio");
  const locale = useLocale() as Locale;
  const refresh = useRefreshCourse(courseId);
  const options = useAuthoringOptions();
  const upload = useUpload(lesson.id);
  const inputId = useId();
  const [tooBig, setTooBig] = useState(false);
  const kind = lesson.type === "audio" ? "audio" : "video";
  const maxMb = options.data?.upload_limits_mb[kind];
  const media = lesson.media;

  // Until the provider has processed the file, ask about it: the API re-checks the provider while processing,
  // and an upload that finished just before a reload shows up once the provider's webhook arrives. Stops after
  // about 5 minutes so an abandoned upload doesn't poll forever.
  const waiting = media?.status === "processing" || media?.status === "waiting_upload";
  const polled = useProfessorMediaShow(media?.id ?? "", {
    query: {
      enabled: Boolean(media && waiting),
      refetchInterval: (query) => (query.state.dataUpdateCount < 60 ? 5_000 : false),
      select: (r) => success(r).data,
    },
  });
  const polledStatus = polled.data?.status;
  useEffect(() => {
    if (polledStatus && polledStatus !== media?.status) void refresh();
  }, [polledStatus, media?.status, refresh]);

  const status = upload && upload.status !== "done" ? upload.status : (media?.status ?? "none");

  return (
    <div className="grid gap-3">
      <div role="status" aria-live="polite" className="grid gap-2">
        {upload?.status === "starting" ? (
          <p className="flex items-center gap-2 text-sm">
            <Loader2 aria-hidden className="size-4 animate-spin" />
            {t("media.starting")}
          </p>
        ) : upload?.status === "uploading" || upload?.status === "offline" ? (
          <div className="grid gap-2">
            <div className="flex items-center justify-between gap-2 text-sm">
              <span className="flex items-center gap-2">
                {upload.status === "offline" ? <WifiOff aria-hidden className="size-4" /> : null}
                {upload.status === "offline" ? t("media.offline") : t("media.uploading")}
              </span>
              {/* The progress bar carries the number; the live region only announces state changes. */}
              <span aria-hidden className="tabular-nums">
                {upload.progress} %
              </span>
            </div>
            <div
              role="progressbar"
              aria-label={t("media.uploading")}
              aria-valuemin={0}
              aria-valuemax={100}
              aria-valuenow={upload.progress}
              className="h-2 overflow-hidden rounded-full bg-muted"
            >
              <div
                className="h-full bg-primary transition-[width]"
                style={{ width: `${upload.progress}%` }}
              />
            </div>
            <p className="text-xs text-muted-foreground">{t("media.keepOpen")}</p>
          </div>
        ) : upload?.status === "failed" ? (
          <p className="flex items-center gap-2 text-sm text-destructive">
            <TriangleAlert aria-hidden className="size-4" />
            {upload.reason === "start" ? t("media.startFailed") : t("media.transferFailed")}
          </p>
        ) : status === "processing" || (status === "waiting_upload" && upload?.status === "done") ? (
          <p className="flex items-center gap-2 text-sm">
            <Loader2 aria-hidden className="size-4 animate-spin" />
            {t("media.processing")}
          </p>
        ) : status === "ready" ? (
          <p className="flex items-center gap-2 text-sm">
            <CheckCircle2 aria-hidden className="size-4 text-success" />
            {media?.duration_seconds
              ? t("media.readyWithDuration", { duration: formatDuration(media.duration_seconds, locale) })
              : t("media.ready")}
          </p>
        ) : status === "errored" ? (
          <p className="flex items-center gap-2 text-sm text-destructive">
            <TriangleAlert aria-hidden className="size-4" />
            {media?.error ? t("media.erroredWith", { error: media.error }) : t("media.errored")}
          </p>
        ) : status === "waiting_upload" ? (
          <p className="text-sm">{t("media.unfinished")}</p>
        ) : (
          <p className="text-sm text-muted-foreground">{t(`media.empty.${kind}`)}</p>
        )}
      </div>

      {editable ? (
        upload?.status === "uploading" || upload?.status === "offline" || upload?.status === "starting" ? (
          <div>
            <Button type="button" variant="outline" size="sm" onClick={() => cancelUpload(lesson.id)}>
              {t("media.cancel")}
            </Button>
          </div>
        ) : (
          <div className="grid gap-2">
            <Label htmlFor={inputId}>
              {media?.status === "ready" ? t("media.replace") : t(`media.choose.${kind}`)}
            </Label>
            <Input
              id={inputId}
              type="file"
              accept={kind === "audio" ? "audio/*" : "video/*"}
              aria-describedby={`${inputId}-hint`}
              onChange={(e) => {
                const file = e.target.files?.[0];
                e.target.value = "";
                if (!file) return;
                if (maxMb && file.size > maxMb * MB) {
                  setTooBig(true);
                  return;
                }
                setTooBig(false);
                clearUpload(lesson.id);
                void startUpload(courseId, lesson.id, file, () => void refresh());
              }}
            />
            <p
              id={`${inputId}-hint`}
              className={tooBig ? "text-sm text-destructive" : "text-xs text-muted-foreground"}
            >
              {maxMb
                ? t(tooBig ? "fileTooBig" : "maxSize", { size: formatBytes(maxMb * 1_000_000, locale) })
                : null}
            </p>
          </div>
        )
      ) : null}
    </div>
  );
}

/** PDF for a document lesson (PRD-03 FR-06), sent to the API which stores and checks it. */
export function DocumentContent({
  courseId,
  lesson,
  editable,
}: {
  courseId: string;
  lesson: StudioLessonResource;
  editable: boolean;
}) {
  const t = useTranslations("web.studio");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const refresh = useRefreshCourse(courseId);
  const options = useAuthoringOptions();
  const inputId = useId();
  const [tooBig, setTooBig] = useState(false);
  const maxMb = options.data?.upload_limits_mb.document;

  const upload = useMutation({
    mutationFn: async (document: File) =>
      success(await professorDraftLessonsDocument(courseId, lesson.id, { document })),
    onSuccess: refresh,
  });
  const documentError = fieldErrorsOf(upload.error).document;

  return (
    <div className="grid gap-3">
      <div role="status" aria-live="polite">
        {upload.isPending ? (
          <p className="flex items-center gap-2 text-sm">
            <Loader2 aria-hidden className="size-4 animate-spin" />
            {t("uploading")}
          </p>
        ) : lesson.document ? (
          <p className="flex items-center gap-2 text-sm">
            <FileText aria-hidden className="size-4" />
            {lesson.document.name} · {formatBytes(lesson.document.size_bytes, locale)}
          </p>
        ) : (
          <p className="text-sm text-muted-foreground">{t("document.empty")}</p>
        )}
      </div>
      {upload.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {documentError ?? errorText(upload.error)}
        </p>
      ) : null}
      {editable ? (
        <div className="grid gap-2">
          <Label htmlFor={inputId}>{lesson.document ? t("document.replace") : t("document.choose")}</Label>
          <Input
            id={inputId}
            type="file"
            accept="application/pdf"
            disabled={upload.isPending}
            aria-describedby={`${inputId}-hint`}
            onChange={(e) => {
              const file = e.target.files?.[0];
              e.target.value = "";
              if (!file) return;
              if (maxMb && file.size > maxMb * MB) {
                setTooBig(true);
                return;
              }
              setTooBig(false);
              upload.mutate(file);
            }}
          />
          <p
            id={`${inputId}-hint`}
            className={tooBig ? "text-sm text-destructive" : "text-xs text-muted-foreground"}
          >
            {maxMb
              ? t(tooBig ? "fileTooBig" : "maxSize", { size: formatBytes(maxMb * 1_000_000, locale) })
              : null}
          </p>
        </div>
      ) : null}
    </div>
  );
}
