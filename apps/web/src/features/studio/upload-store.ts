"use client";

import type { UpChunk, UpChunkOptions } from "@mux/upchunk";
import { success } from "@yekkola/api-client";
import { professorDraftLessonsMedia } from "@yekkola/api-client/endpoints/draft";
import { useSyncExternalStore } from "react";

/**
 * Video/audio uploads in progress, kept outside React so they keep going while the professor moves between the
 * editor's tabs (PRD-03 §6, FR-04). Files go straight from the browser to the provider's direct-upload URL in
 * chunks; UpChunk retries failed chunks and pauses while offline, then resumes.
 */
export type UploadState =
  | { status: "starting" }
  | { status: "uploading"; progress: number }
  | { status: "offline"; progress: number }
  | { status: "failed"; reason: "start" | "transfer" }
  | { status: "done" };

/** 5 MB chunks: small enough to retry cheaply on a weak connection (multiple of 256 kB, as UpChunk requires). */
const CHUNK_KB = 5_120;

const uploads = new Map<string, UploadState>();
const running = new Map<string, UpChunk>();
const listeners = new Set<() => void>();

function set(lessonId: string, state: UploadState | null) {
  if (state === null) uploads.delete(lessonId);
  else uploads.set(lessonId, state);
  syncUnloadGuard();
  listeners.forEach((listener) => listener());
}

function subscribe(listener: () => void) {
  listeners.add(listener);
  return () => listeners.delete(listener);
}

function isActive(state: UploadState | undefined): boolean {
  return state?.status === "starting" || state?.status === "uploading" || state?.status === "offline";
}

/** Leaving the page would cut an upload: the browser asks first. */
function warnBeforeUnload(event: BeforeUnloadEvent) {
  event.preventDefault();
}

let guarded = false;
function syncUnloadGuard() {
  const active = [...uploads.values()].some(isActive);
  if (active && !guarded) window.addEventListener("beforeunload", warnBeforeUnload);
  if (!active && guarded) window.removeEventListener("beforeunload", warnBeforeUnload);
  guarded = active;
}

/** The upload for one lesson, if any (re-renders on every change). */
export function useUpload(lessonId: string): UploadState | undefined {
  return useSyncExternalStore(
    subscribe,
    () => uploads.get(lessonId),
    () => undefined,
  );
}

/**
 * Asks the API for a fresh upload URL (each attempt gets its own, so an expired one is never reused), then sends
 * the file. `onChange` runs when the upload starts (the lesson now points at the new, empty asset) and when it
 * ends (the provider has the whole file, or it failed), to refresh the lesson.
 */
export async function startUpload(courseId: string, lessonId: string, file: File, onChange: () => void) {
  if (isActive(uploads.get(lessonId))) return;
  set(lessonId, { status: "starting" });

  let url: string;
  let createUpload: (options: UpChunkOptions) => UpChunk;
  try {
    // Loaded only when a professor uploads (it needs the browser's XHR and file APIs).
    [url, { createUpload }] = await Promise.all([
      professorDraftLessonsMedia(courseId, lessonId).then((r) => success(r).data.upload_url),
      import("@mux/upchunk"),
    ]);
  } catch {
    set(lessonId, { status: "failed", reason: "start" });
    onChange();
    return;
  }
  onChange();

  const upload = createUpload({ endpoint: url, file, chunkSize: CHUNK_KB });
  running.set(lessonId, upload);
  set(lessonId, { status: "uploading", progress: 0 });

  const progressOf = () => {
    const state = uploads.get(lessonId);
    return state && "progress" in state ? state.progress : 0;
  };
  upload.on("progress", (event) =>
    set(lessonId, { status: "uploading", progress: Math.round(Number(event.detail)) }),
  );
  upload.on("offline", () => set(lessonId, { status: "offline", progress: progressOf() }));
  upload.on("online", () => set(lessonId, { status: "uploading", progress: progressOf() }));
  upload.on("error", () => {
    running.delete(lessonId);
    set(lessonId, { status: "failed", reason: "transfer" });
    onChange();
  });
  upload.on("success", () => {
    running.delete(lessonId);
    set(lessonId, { status: "done" });
    onChange();
  });
}

/**
 * Stops an upload. The lesson already points at the new asset, which stays waiting for a file: choosing the file
 * again starts over (a replaced file is not restored — TODO 2.5).
 */
export function cancelUpload(lessonId: string) {
  running.get(lessonId)?.abort();
  running.delete(lessonId);
  set(lessonId, null);
}

/** Forget a finished or failed upload once its result has been shown. */
export function clearUpload(lessonId: string) {
  if (!isActive(uploads.get(lessonId))) set(lessonId, null);
}
