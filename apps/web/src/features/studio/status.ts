import type { StatusTone } from "@yekkola/ui/blocks/status-badge";

export type CourseStatus = "draft" | "in_review" | "published" | "unpublished" | "archived";
export type VersionState = "draft" | "in_review" | "rejected" | "approved" | "superseded";

export const COURSE_TONES: Record<CourseStatus, StatusTone> = {
  draft: "neutral",
  in_review: "warning",
  published: "success",
  unpublished: "neutral",
  archived: "neutral",
};

export function courseStatusOf(status: string): CourseStatus {
  return status in COURSE_TONES ? (status as CourseStatus) : "draft";
}

/**
 * The editor's banner (PRD-03 §6): what students see now and what happens to the draft. "Live + draft in review"
 * and "rejected" come from the draft version; the rest from the course.
 */
export type Banner =
  "draft" | "in_review" | "rejected" | "live" | "live_draft" | "live_in_review" | "unpublished" | "archived";

export function bannerOf(course: {
  status: string;
  live_version: { id: string } | null;
  draft_version: { state: string } | null;
}): Banner {
  const draft = course.draft_version?.state;
  if (course.status === "archived") return "archived";
  if (draft === "rejected") return "rejected";
  if (course.status === "unpublished") return "unpublished";
  if (course.live_version) {
    if (draft === "in_review") return "live_in_review";
    return draft ? "live_draft" : "live";
  }
  return draft === "in_review" ? "in_review" : "draft";
}
