"use client";

import type { PlaybackResource } from "@yekkola/api-client/model";
import type Hls from "hls.js";
import { useTranslations } from "next-intl";
import { useEffect, useRef, useState } from "react";

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

/** Re-loading the playlist after a fatal network error: this many times, then show an error. */
const MAX_RELOADS = 2;

/**
 * Encrypted HLS from our API (PRD-07 FR-02): hls.js loads the playlists and the AES-128 key with the viewer's
 * credentials (the API checks access on each request) and the segments from short-lived signed URLs without
 * them. Safari plays HLS natively. The per-viewer watermark is drawn over it, moving every 45 s (FR-04).
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
  const t = useTranslations("web.player");
  const isAudio = playback.kind === "audio";
  const media = useRef<HTMLVideoElement & HTMLAudioElement>(null);
  const lastReport = useRef(0);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    const element = media.current;
    if (!element) return;
    let hls: Hls | null = null;
    let cancelled = false;
    let reloads = 0;
    const apiOrigin = new URL(playback.playlist_url).origin;

    const seekToStart = () => {
      if (startTime && element.currentTime < 1) element.currentTime = startTime;
    };
    element.addEventListener("loadedmetadata", seekToStart, { once: true });

    void import("hls.js").then(({ default: HlsClass }) => {
      if (cancelled) return;
      if (!HlsClass.isSupported()) {
        // Safari / iOS: native HLS (cookies go to the API, which shares our parent domain).
        if (element.canPlayType("application/vnd.apple.mpegurl")) element.src = playback.playlist_url;
        else setFailed(true);
        return;
      }

      hls = new HlsClass({
        // Credentials only to our API (playlists, key) — never to the signed segment URLs.
        xhrSetup: (xhr, url) => {
          xhr.withCredentials = new URL(url, window.location.href).origin === apiOrigin;
        },
      });
      const instance = hls;
      // A recovery worked once segments load again: later expiries get their own reloads.
      instance.on(HlsClass.Events.FRAG_LOADED, () => {
        reloads = 0;
      });
      instance.on(HlsClass.Events.ERROR, (_event, data) => {
        if (!data.fatal) return;
        if (data.type === HlsClass.ErrorTypes.MEDIA_ERROR) {
          instance.recoverMediaError();
        } else if (data.type === HlsClass.ErrorTypes.NETWORK_ERROR && reloads < MAX_RELOADS) {
          // Signed segment URLs may have expired during a long pause: fresh playlists sign them again.
          reloads += 1;
          const at = element.currentTime;
          instance.loadSource(playback.playlist_url);
          instance.startLoad(at);
        } else {
          setFailed(true);
          instance.destroy();
        }
      });
      instance.loadSource(playback.playlist_url);
      instance.attachMedia(element);
    });

    return () => {
      cancelled = true;
      element.removeEventListener("loadedmetadata", seekToStart);
      hls?.destroy();
      element.removeAttribute("src");
    };
  }, [playback.playlist_url, startTime]);

  useEffect(() => {
    if (stopped) media.current?.pause();
  }, [stopped]);

  const position = () => [media.current?.currentTime ?? 0, media.current?.duration ?? 0] as const;
  const handlers = {
    ref: media,
    title,
    controls: true,
    autoPlay,
    playsInline: true,
    preload: "metadata" as const,
    controlsList: "nodownload",
    onContextMenu: (e: React.MouseEvent) => e.preventDefault(),
    onTimeUpdate: () => {
      const now = Date.now();
      if (now - lastReport.current < 1000) return;
      lastReport.current = now;
      onProgress?.(...position());
    },
    onPlay: () => {
      if (stopped) media.current?.pause();
    },
    onPause: () => onStop?.(...position()),
    onEnded: () => onStop?.(...position()),
  };

  return (
    <div className="relative overflow-hidden rounded-md bg-black">
      {isAudio ? (
        <audio {...handlers} className="w-full" />
      ) : (
        <video
          {...handlers}
          poster={playback.poster_url ?? undefined}
          className="aspect-video w-full bg-black"
        />
      )}
      {failed ? (
        <p role="alert" className="absolute inset-x-0 top-0 bg-black/80 p-3 text-center text-sm text-white">
          {t("loadFailed")}
        </p>
      ) : null}
      {playback.watermark?.text && !isAudio ? <WatermarkOverlay text={playback.watermark.text} /> : null}
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
