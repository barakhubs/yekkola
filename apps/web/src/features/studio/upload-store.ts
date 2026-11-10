"use client";

import { ApiError, success } from "@yekkola/api-client";
import { professorDraftLessonsMedia } from "@yekkola/api-client/endpoints/draft";
import { professorMediaComplete, professorMediaParts } from "@yekkola/api-client/endpoints/media";
import { useSyncExternalStore } from "react";

/**
 * Video/audio uploads in progress, kept outside React so they keep going while the professor moves between the
 * editor's tabs (PRD-03 §6, FR-04). Files go straight from the browser to storage (R2) in parts, with pre-signed
 * part URLs from the API: a failed part is retried, an expired URL is replaced, and the upload waits while
 * offline, then resumes. Once every part is in, the API is told to process the file.
 */
export type UploadState =
  | { status: "starting" }
  | { status: "uploading"; progress: number }
  | { status: "offline"; progress: number }
  | { status: "failed"; reason: "start" | "transfer" }
  | { status: "done" };

/** Parts sent at the same time: enough to fill a good connection, few enough for a weak one. */
const PARALLEL_PARTS = 3;
/** Attempts per part before the upload gives up (offline waits don't count). */
const MAX_ATTEMPTS = 6;

type Part = { number: number; url: string };

class UploadCancelled extends Error {}

interface RunningUpload {
  cancelled: boolean;
  requests: Set<XMLHttpRequest>;
}

const uploads = new Map<string, UploadState>();
const running = new Map<string, RunningUpload>();
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

const wait = (ms: number) => new Promise((resolve) => window.setTimeout(resolve, ms));

function waitUntilOnline(): Promise<void> {
  if (navigator.onLine) return Promise.resolve();
  return new Promise((resolve) => window.addEventListener("online", () => resolve(), { once: true }));
}

/** PUT one part; resolves with its ETag. `status` 0 = network error. */
function putPart(
  job: RunningUpload,
  url: string,
  body: Blob,
  onProgress: (sent: number) => void,
): Promise<string> {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    job.requests.add(xhr);
    xhr.open("PUT", url);
    xhr.upload.onprogress = (event) => onProgress(event.loaded);
    xhr.onload = () => {
      job.requests.delete(xhr);
      // R2 exposes the ETag header (bucket CORS); the fake driver also returns it in the body, in case a proxy
      // or CORS hides the header.
      let etag = xhr.getResponseHeader("ETag");
      if (!etag) {
        try {
          const body = JSON.parse(xhr.responseText) as { etag?: unknown };
          if (typeof body.etag === "string") etag = body.etag;
        } catch {
          // not JSON: no ETag
        }
      }
      if (xhr.status >= 200 && xhr.status < 300 && etag) resolve(etag);
      else reject(Object.assign(new Error("part failed"), { status: xhr.status }));
    };
    xhr.onerror = () => {
      job.requests.delete(xhr);
      reject(Object.assign(new Error("network"), { status: 0 }));
    };
    xhr.onabort = () => {
      job.requests.delete(xhr);
      reject(new UploadCancelled());
    };
    xhr.send(body);
  });
}

/**
 * Asks the API to start a fresh upload (each attempt gets its own, so an expired one is never reused), then sends
 * the file in parts and confirms it. `onChange` runs when the upload starts (the lesson now points at the new,
 * empty file) and when it ends (processing has started, or it failed), to refresh the lesson.
 */
export async function startUpload(courseId: string, lessonId: string, file: File, onChange: () => void) {
  if (isActive(uploads.get(lessonId))) return;
  const job: RunningUpload = { cancelled: false, requests: new Set() };
  running.set(lessonId, job);
  set(lessonId, { status: "starting" });

  let mediaId: string;
  let partSize: number;
  let partCount: number;
  const urls = new Map<number, string>();
  try {
    const started = success(
      await professorDraftLessonsMedia(courseId, lessonId, {
        size_bytes: file.size,
        content_type: file.type || "application/octet-stream",
      }),
    ).data;
    mediaId = started.media.id;
    partSize = started.upload.part_size_bytes;
    partCount = started.upload.part_count;
    started.upload.parts.forEach((p: Part) => urls.set(p.number, p.url));
  } catch {
    if (running.get(lessonId) !== job) return; // cancelled, or replaced by a newer upload
    running.delete(lessonId);
    set(lessonId, { status: "failed", reason: "start" });
    onChange();
    return;
  }
  if (running.get(lessonId) !== job) return;
  onChange();

  const sent = new Map<number, number>();
  const progress = () => {
    let total = 0;
    sent.forEach((bytes) => (total += bytes));
    return Math.min(100, Math.round((total / Math.max(1, file.size)) * 100));
  };
  const report = () => {
    if (job.cancelled) return;
    set(lessonId, { status: navigator.onLine ? "uploading" : "offline", progress: progress() });
  };
  report();

  /** URLs for this part and the next ones still missing (one call per batch of up to 100). */
  const urlFor = async (number: number, fresh = false): Promise<string> => {
    if (fresh) urls.delete(number);
    const known = urls.get(number);
    if (known) return known;
    const wanted: number[] = [];
    for (let n = number; n <= partCount && wanted.length < 100; n++) {
      if (n === number || !urls.has(n)) wanted.push(n);
    }
    const data = success(await professorMediaParts(mediaId, { parts: wanted })).data;
    data.parts.forEach((p: Part) => urls.set(p.number, p.url));
    const url = urls.get(number);
    if (!url) throw new Error("no url");
    return url;
  };

  const sendPart = async (number: number): Promise<{ number: number; etag: string }> => {
    const body = file.slice((number - 1) * partSize, Math.min(file.size, number * partSize));
    for (let attempt = 1; ; attempt++) {
      if (job.cancelled) throw new UploadCancelled();
      await waitUntilOnline();
      report();
      try {
        const url = await urlFor(number, attempt > 1);
        if (job.cancelled) throw new UploadCancelled();
        const etag = await putPart(job, url, body, (bytes) => {
          sent.set(number, bytes);
          report();
        });
        sent.set(number, body.size);
        report();
        return { number, etag };
      } catch (error) {
        if (error instanceof UploadCancelled) throw error;
        sent.set(number, 0);
        if (!navigator.onLine) {
          report();
          continue; // offline waits don't use up attempts
        }
        if (attempt >= MAX_ATTEMPTS) throw error;
        await wait(Math.min(30_000, 1_000 * 2 ** attempt));
      }
    }
  };

  const onOnline = () => report();
  const onOffline = () => report();
  window.addEventListener("online", onOnline);
  window.addEventListener("offline", onOffline);

  try {
    const done: { number: number; etag: string }[] = [];
    let next = 1;
    const worker = async () => {
      while (next <= partCount) {
        const number = next++;
        done.push(await sendPart(number));
      }
    };
    await Promise.all(Array.from({ length: Math.min(PARALLEL_PARTS, partCount) }, worker));
    if (job.cancelled) throw new UploadCancelled();

    done.sort((a, b) => a.number - b.number);
    // Confirming is safe to repeat on the API: retry it like a part rather than lose the whole upload.
    for (let attempt = 1; ; attempt++) {
      if (job.cancelled) throw new UploadCancelled();
      await waitUntilOnline();
      try {
        await professorMediaComplete(mediaId, { parts: done });
        break;
      } catch (error) {
        // A refusal (missing parts, too large) won't change on retry; network and server errors might.
        if (error instanceof ApiError && error.status < 500) throw error;
        if (attempt >= MAX_ATTEMPTS) throw error;
        await wait(Math.min(30_000, 1_000 * 2 ** attempt));
      }
    }
    if (running.get(lessonId) !== job) return;
    running.delete(lessonId);
    set(lessonId, { status: "done" });
  } catch (error) {
    if (running.get(lessonId) !== job) {
      job.cancelled = true;
      job.requests.forEach((xhr) => xhr.abort());
      return; // cancelled, or replaced by a newer upload: leave its state alone
    }
    running.delete(lessonId);
    const cancelledByUser = job.cancelled;
    job.cancelled = true; // stop the other parts too
    job.requests.forEach((xhr) => xhr.abort());
    if (error instanceof UploadCancelled || cancelledByUser) return;
    set(lessonId, { status: "failed", reason: "transfer" });
  } finally {
    window.removeEventListener("online", onOnline);
    window.removeEventListener("offline", onOffline);
  }
  onChange();
}

/**
 * Stops an upload. The lesson already points at the new file, which stays waiting: choosing the file again starts
 * over (a replaced file is not restored — TODO 2.5).
 */
export function cancelUpload(lessonId: string) {
  const job = running.get(lessonId);
  if (job) {
    job.cancelled = true;
    job.requests.forEach((xhr) => xhr.abort());
  }
  running.delete(lessonId);
  set(lessonId, null);
}

/** Forget a finished or failed upload once its result has been shown. */
export function clearUpload(lessonId: string) {
  if (!isActive(uploads.get(lessonId))) set(lessonId, null);
}
