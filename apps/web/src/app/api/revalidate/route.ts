import { createHmac, timingSafeEqual } from "node:crypto";

import { revalidateTag } from "next/cache";
import { NextResponse } from "next/server";

/** Tags the public data layer uses (src/lib/public-api.ts). Anything else is refused. */
const ALLOWED = /^(courses|categories|course:[a-z0-9-]{1,200}|professor:[a-z0-9-]{1,200})$/;
const MAX_SKEW_SECONDS = 300;

/**
 * Called by the API when a course or professor changes (PRD-04 FR-11, FR-12): drops the cached pages that
 * show it, so a takedown or price change is visible on the next visit. Signed with WEB_REVALIDATE_SECRET.
 */
export async function POST(request: Request) {
  const secret = process.env.WEB_REVALIDATE_SECRET;
  const timestamp = request.headers.get("x-revalidate-timestamp") ?? "";
  const signature = request.headers.get("x-revalidate-signature") ?? "";
  const body = await request.text();

  if (!secret || !isFresh(timestamp) || !isValid(signature, createHmac("sha256", secret).update(`${timestamp}.${body}`).digest("hex"))) {
    return NextResponse.json({ revalidated: false }, { status: 401 });
  }

  let tags: unknown;
  try {
    tags = (JSON.parse(body) as { tags?: unknown }).tags;
  } catch {
    tags = undefined;
  }
  if (!Array.isArray(tags) || tags.length === 0 || tags.length > 50 || !tags.every((t) => typeof t === "string" && ALLOWED.test(t))) {
    return NextResponse.json({ revalidated: false }, { status: 422 });
  }

  // Expire now: the next visitor gets fresh data, never the stale page (e.g. after a takedown).
  for (const tag of new Set(tags as string[])) {
    revalidateTag(tag, { expire: 0 });
  }

  return NextResponse.json({ revalidated: true, tags });
}

function isFresh(timestamp: string): boolean {
  const seconds = Number.parseInt(timestamp, 10);
  return Number.isFinite(seconds) && Math.abs(Date.now() / 1000 - seconds) <= MAX_SKEW_SECONDS;
}

function isValid(given: string, expected: string): boolean {
  const a = Buffer.from(given);
  const b = Buffer.from(expected);
  return a.length === b.length && timingSafeEqual(a, b);
}
