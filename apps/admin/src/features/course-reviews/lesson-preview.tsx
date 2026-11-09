"use client";

import { apiUrl, success } from "@yekkola/api-client";
import { useAdminLessonPreviewsShow } from "@yekkola/api-client/endpoints/lesson-preview";
import { Button } from "@yekkola/ui/components/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@yekkola/ui/components/dialog";
import { Skeleton } from "@yekkola/ui/components/skeleton";
import type Hls from "hls.js";
import { ExternalLink, PlayCircle } from "lucide-react";
import { useTranslations } from "next-intl";
import { useEffect, useRef, useState } from "react";

import { useErrorText } from "@/features/common/errors";

/**
 * Preview one lesson of the version under review (PRD-09 FR-07): video/audio through the staff playlists (hls.js
 * with the staff session), documents as the professor's original PDF in a new tab (each opening is audited).
 */
export function LessonPreview({
  versionId,
  lessonId,
  title,
  kind,
}: {
  versionId: string;
  lessonId: string;
  title: string;
  kind: "video" | "audio" | "document";
}) {
  const t = useTranslations("admin.reviews");
  const common = useTranslations("common");
  const [open, setOpen] = useState(false);

  if (kind === "document") {
    return (
      <Button asChild size="sm" variant="outline" className="w-fit">
        {/* rel without noreferrer: the API needs the Referer to load the staff session. */}
        <a
          href={apiUrl(`/api/v1/admin/course-versions/${versionId}/lessons/${lessonId}/document`)}
          target="_blank"
          rel="noopener"
        >
          {t("openDocument")}
          <ExternalLink aria-hidden className="size-3.5" />
        </a>
      </Button>
    );
  }

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button size="sm" variant="outline" className="w-fit">
          <PlayCircle aria-hidden className="size-4" />
          {t("playLesson")}
        </Button>
      </DialogTrigger>
      <DialogContent closeLabel={common("close")} className="max-w-3xl">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{t("playHint")}</DialogDescription>
        </DialogHeader>
        {open ? <PreviewPlayer versionId={versionId} lessonId={lessonId} /> : null}
      </DialogContent>
    </Dialog>
  );
}

function PreviewPlayer({ versionId, lessonId }: { versionId: string; lessonId: string }) {
  const t = useTranslations("admin.reviews");
  const errorText = useErrorText();
  const preview = useAdminLessonPreviewsShow(versionId, lessonId, {
    query: { select: (r) => success(r).data, staleTime: 0, gcTime: 0 },
  });
  const media = useRef<HTMLVideoElement & HTMLAudioElement>(null);
  const [failed, setFailed] = useState(false);
  const playlist = preview.data?.playlist_url ?? null;

  useEffect(() => {
    const element = media.current;
    if (!element || !playlist) return;
    let hls: Hls | null = null;
    let cancelled = false;
    const apiOrigin = new URL(playlist).origin;

    void import("hls.js").then(({ default: HlsClass }) => {
      if (cancelled) return;
      if (!HlsClass.isSupported()) {
        if (element.canPlayType("application/vnd.apple.mpegurl")) element.src = playlist;
        else setFailed(true);
        return;
      }
      hls = new HlsClass({
        // Staff session cookies only to our API — never to the signed segment URLs.
        xhrSetup: (xhr, url) => {
          xhr.withCredentials = new URL(url, window.location.href).origin === apiOrigin;
        },
      });
      hls.on(HlsClass.Events.ERROR, (_event, data) => {
        if (data.fatal) setFailed(true);
      });
      hls.loadSource(playlist);
      hls.attachMedia(element);
    });

    return () => {
      cancelled = true;
      hls?.destroy();
      element.removeAttribute("src");
    };
  }, [playlist]);

  if (preview.isPending) return <Skeleton className="aspect-video w-full" />;
  if (preview.isError) {
    return (
      <p role="alert" className="text-sm text-destructive">
        {errorText(preview.error)}
      </p>
    );
  }

  return (
    <div className="grid gap-2">
      {preview.data.kind === "audio" ? (
        <audio ref={media} controls className="w-full" />
      ) : (
        <video
          ref={media}
          controls
          playsInline
          poster={preview.data.poster_url ?? undefined}
          className="aspect-video w-full rounded-md bg-black"
        />
      )}
      {failed ? (
        <p role="alert" className="text-sm text-destructive">
          {t("playFailed")}
        </p>
      ) : null}
    </div>
  );
}
