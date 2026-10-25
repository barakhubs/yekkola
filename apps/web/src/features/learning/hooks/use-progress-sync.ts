"use client";

import { primeCsrf } from "@yekkola/api-client";
import { meProgressSync } from "@yekkola/api-client/endpoints/learning";
import { useCallback, useEffect, useRef } from "react";

import { mergeProgress, type ProgressItem, shouldRetry } from "../progress-queue";

const FLUSH_EVERY_MS = 15_000;

/**
 * Queues progress and sends it in batches (PRD-06 FR-05): every 15 s, on pause/end, and when the page is
 * hidden or left (`keepalive`). Failed batches are merged back — never overwritten by a lower position — and
 * retried only when the failure is transient. The API merges monotonically too.
 */
export function useProgressSync(onSynced?: () => void) {
  const pending = useRef(new Map<string, ProgressItem>());
  const inFlight = useRef(false);
  const synced = useRef(onSynced);
  useEffect(() => {
    synced.current = onSynced;
  }, [onSynced]);

  const flush = useCallback(async (keepalive = false) => {
    if (pending.current.size === 0 || (inFlight.current && !keepalive)) return;
    const items = [...pending.current.values()];
    pending.current.clear();
    inFlight.current = true;
    try {
      await meProgressSync({ items }, keepalive ? { keepalive: true } : undefined);
      synced.current?.();
    } catch (error) {
      if (shouldRetry(error)) {
        for (const item of items) {
          pending.current.set(item.lesson_uid, mergeProgress(pending.current.get(item.lesson_uid), item));
        }
      }
    } finally {
      inFlight.current = false;
    }
  }, []);

  const record = useCallback((item: Omit<ProgressItem, "client_updated_at">) => {
    pending.current.set(
      item.lesson_uid,
      mergeProgress(pending.current.get(item.lesson_uid), {
        ...item,
        client_updated_at: new Date().toISOString(),
      }),
    );
  }, []);

  useEffect(() => {
    // Leaving the page can't wait for a CSRF round trip: have the cookie ready.
    void primeCsrf();
    const timer = window.setInterval(() => void flush(), FLUSH_EVERY_MS);
    const onHide = () => {
      if (document.visibilityState === "hidden") void flush(true);
    };
    const onLeave = () => void flush(true);
    document.addEventListener("visibilitychange", onHide);
    window.addEventListener("pagehide", onLeave);

    return () => {
      window.clearInterval(timer);
      document.removeEventListener("visibilitychange", onHide);
      window.removeEventListener("pagehide", onLeave);
      void flush(true);
    };
  }, [flush]);

  return { record, flush };
}
