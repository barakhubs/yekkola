"use client";

import MuxPlayer from "@mux/mux-player-react";
import type { PlaybackResource } from "@yekkola/api-client/model";
import { type ComponentRef, useEffect, useRef, useState } from "react";

type PlayerElement = ComponentRef<typeof MuxPlayer>;

interface ProtectedPlayerProps {
  playback: PlaybackResource;
  title: string;
  autoPlay?: boolean;
  /** Resume position in seconds. */
  startTime?: number;
  /** Fires about every second while playing (current position, total duration). */
  onProgress?: (seconds: number, duration: number) => void;
  /** Paused or ended: a good moment to save progress. */
  onStop?: (seconds: number, duration: number) => void;
  /** Another screen took over (web stream limit): playback is stopped and can't resume. */
  stopped?: boolean;
}

/**
 * Mux Player with signed playback (+ DRM for video) tokens from the API (PRD-07 FR-02) and the per-viewer
 * watermark drawn over it, moving every 45 s (FR-04). Never a plain media URL.
 */
export function ProtectedPlayer({
  playback,
  title,
  autoPlay = false,
  startTime,
  onProgress,
  onStop,
  stopped = false,
}: ProtectedPlayerProps) {
  const isAudio = playback.kind === "audio";
  const player = useRef<PlayerElement>(null);
  const lastReport = useRef(0);

  useEffect(() => {
    if (stopped) player.current?.pause();
  }, [stopped]);

  const position = () => [player.current?.currentTime ?? 0, player.current?.duration ?? 0] as const;

  return (
    <div className="relative overflow-hidden rounded-md bg-black">
      <MuxPlayer
        ref={player}
        playbackId={playback.playback_id}
        tokens={{
          playback: playback.tokens.playback,
          drm: playback.tokens.drm ?? undefined,
          thumbnail: playback.tokens.thumbnail ?? undefined,
          storyboard: playback.tokens.storyboard ?? undefined,
        }}
        audio={isAudio}
        streamType="on-demand"
        metadata={{ video_title: title }}
        autoPlay={autoPlay}
        startTime={startTime}
        playbackRates={[0.75, 1, 1.25, 1.5, 2]}
        accentColor="#2c3892"
        style={{ aspectRatio: isAudio ? undefined : "16 / 9", width: "100%" }}
        onTimeUpdate={() => {
          const now = Date.now();
          if (now - lastReport.current < 1000) return;
          lastReport.current = now;
          onProgress?.(...position());
        }}
        onPlay={() => {
          if (stopped) player.current?.pause();
        }}
        onPause={() => onStop?.(...position())}
        onEnded={() => onStop?.(...position())}
      />
      {playback.watermark?.text ? <WatermarkOverlay text={playback.watermark.text} /> : null}
    </div>
  );
}

const POSITIONS = [
  "top-4 left-4",
  "top-4 right-4",
  "bottom-16 left-4",
  "bottom-16 right-4",
  "top-1/2 left-1/2 -translate-x-1/2",
];

/** Viewer name + masked phone over the video, moving to a new spot every 45 s (a deterrent, PRD-07 FR-04). */
function WatermarkOverlay({ text }: { text: string }) {
  const [position, setPosition] = useState(0);

  useEffect(() => {
    const timer = window.setInterval(
      () => setPosition((p) => (p + 1 + Math.floor(Math.random() * 3)) % POSITIONS.length),
      45_000,
    );
    return () => window.clearInterval(timer);
  }, []);

  return (
    <span
      aria-hidden
      className={`pointer-events-none absolute ${POSITIONS[position]} rounded bg-black/30 px-2 py-1 text-xs font-semibold text-white/70 select-none`}
    >
      {text}
    </span>
  );
}
