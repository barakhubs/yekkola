"use client";

import { useQuery } from "@tanstack/react-query";
import { ApiError, success } from "@yekkola/api-client";
import { lessonsPlayback, lessonsPlaybackHeartbeat } from "@yekkola/api-client/endpoints/playback";
import { EmptyState } from "@yekkola/ui/blocks/empty-state";
import { Button } from "@yekkola/ui/components/button";
import { Skeleton } from "@yekkola/ui/components/skeleton";
import { MonitorSmartphone } from "lucide-react";
import dynamic from "next/dynamic";
import { useTranslations } from "next-intl";
import { useEffect, useState } from "react";

const ProtectedPlayer = dynamic(
  () => import("@/features/player/components/protected-player").then((m) => m.ProtectedPlayer),
  { ssr: false, loading: () => <Skeleton className="aspect-video w-full" /> },
);

interface MediaLessonProps {
  lessonUid: string;
  title: string;
  /** Saved position, so playback resumes where the student left off (any device). */
  startAt: number;
  onPosition: (seconds: number, final: boolean) => void;
}

/**
 * A video or audio lesson (PRD-06 FR-03): fresh signed tokens for this lesson, the per-viewer watermark, and the
 * web stream heartbeat — when another screen takes over, playback stops with an explanation (PRD-07 FR-05).
 */
export function MediaLesson({ lessonUid, title, startAt, onPosition }: MediaLessonProps) {
  const t = useTranslations("web.learn");
  const common = useTranslations("common");
  const [attempt, setAttempt] = useState(0);
  const [replaced, setReplaced] = useState(false);

  const playback = useQuery({
    queryKey: ["playback", lessonUid, attempt],
    queryFn: async () => success(await lessonsPlayback(lessonUid)).data,
    staleTime: Number.POSITIVE_INFINITY, // tokens are fetched once per viewing
    gcTime: 0,
    retry: false,
  });

  // Keep the web stream counted as live; stop when another screen wins (newest stream wins).
  const session = playback.data?.stream;
  useEffect(() => {
    if (!session?.session_id) return;
    const sessionId = session.session_id;
    const timer = window.setInterval(async () => {
      try {
        await lessonsPlaybackHeartbeat(lessonUid, { session_id: sessionId });
      } catch (error) {
        if (error instanceof ApiError && error.code === "playback.stream_replaced") {
          setReplaced(true);
          window.clearInterval(timer);
        }
      }
    }, session.heartbeat_seconds * 1000);

    return () => window.clearInterval(timer);
  }, [lessonUid, session]);

  if (playback.isPending) {
    return <Skeleton className="aspect-video w-full" aria-label={t("loadingLesson")} />;
  }

  if (playback.isError) {
    const processing =
      playback.error instanceof ApiError && playback.error.code === "playback.media_not_ready";
    return (
      <EmptyState
        title={processing ? t("mediaProcessing") : t("lessonError")}
        action={
          <Button variant="outline" onClick={() => setAttempt((n) => n + 1)}>
            {common("retry")}
          </Button>
        }
      />
    );
  }

  return (
    <div className="relative">
      <ProtectedPlayer
        playback={playback.data}
        title={title}
        startTime={startAt > 5 ? startAt : undefined}
        stopped={replaced}
        onProgress={(seconds) => onPosition(seconds, false)}
        onStop={(seconds) => onPosition(seconds, true)}
      />
      {replaced ? (
        <div className="absolute inset-0 grid place-items-center rounded-md bg-black/80 p-4 text-center text-white">
          <div className="grid justify-items-center gap-3">
            <MonitorSmartphone aria-hidden className="size-8" />
            <p role="status" className="font-semibold">
              {t("playingElsewhere")}
            </p>
            <Button
              variant="accent"
              onClick={() => {
                setReplaced(false);
                setAttempt((n) => n + 1); // a new stream session takes over from the other screen
              }}
            >
              {t("playHere")}
            </Button>
          </div>
        </div>
      ) : null}
    </div>
  );
}
