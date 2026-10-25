"use client";

import { meProgressSync } from "@yekkola/api-client/endpoints/learning";
import type { SyncProgressRequestItemsItem } from "@yekkola/api-client/model";
import { useCallback, useEffect, useRef } from "react";

const FLUSH_EVERY_MS = 15_000;

/**
 * Queues progress and sends it in batches (PRD-06 FR-05): every 15 s, on pause/end, and when the page is
 * hidden or left. The API merges monotonically, so a late or repeated batch never undoes progress.
 */
export function useProgressSync(onSynced?: () => void) {
  const pending = useRef(new Map<string, SyncProgressRequestItemsItem>());
  const synced = useRef(onSynced);
  useEffect(() => {
    synced.current = onSynced;
  }, [onSynced]);

  const flush = useCallback(async (keepalive = false) => {
    if (pending.current.size === 0) return;
    const items = [...pending.current.values()];
    pending.current.clear();
    try {
      await meProgressSync({ items }, keepalive ? { keepalive: true } : undefined);
      synced.current?.();
    } catch {
      // Keep them for the next flush (offline, server error); a newer entry for the same lesson wins.
      for (const item of items) {
        if (!pending.current.has(item.lesson_uid)) pending.current.set(item.lesson_uid, item);
      }
    }
  }, []);

  const record = useCallback((item: Omit<SyncProgressRequestItemsItem, "client_updated_at">) => {
    const previous = pending.current.get(item.lesson_uid);
    pending.current.set(item.lesson_uid, {
      ...previous,
      ...item,
      completed: Boolean(previous?.completed || item.completed) || undefined,
      client_updated_at: new Date().toISOString(),
    });
  }, []);

  useEffect(() => {
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
