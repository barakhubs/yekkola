"use client";

import type { PlaybackResource } from "@yekkola/api-client/model";
import MuxPlayer from "@mux/mux-player-react";
import { useEffect, useState } from "react";

interface ProtectedPlayerProps {
  playback: PlaybackResource;
  title: string;
  autoPlay?: boolean;
}

/**
 * Mux Player with signed playback (+ DRM for video) tokens from the API (PRD-07 FR-02) and the per-viewer
 * watermark drawn over it, moving every 45 s (FR-04). Never a plain media URL.
 */
export function ProtectedPlayer({ playback, title, autoPlay = false }: ProtectedPlayerProps) {
  const isAudio = playback.kind === "audio";

  return (
    <div className="relative overflow-hidden rounded-md bg-black">
      <MuxPlayer
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
        playbackRates={[0.75, 1, 1.25, 1.5, 2]}
        accentColor="#2c3892"
        style={{ aspectRatio: isAudio ? undefined : "16 / 9", width: "100%" }}
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
