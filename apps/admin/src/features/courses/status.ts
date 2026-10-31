import type { StatusTone } from "@yekkola/ui/blocks/status-badge";

export type CourseState = "draft" | "in_review" | "published" | "unpublished" | "archived";

export const COURSE_TONES: Record<CourseState, StatusTone> = {
  draft: "neutral",
  in_review: "warning",
  published: "success",
  unpublished: "neutral",
  archived: "neutral",
};

export const COURSE_STATES = Object.keys(COURSE_TONES) as CourseState[];

export function courseStateOf(status: string): CourseState {
  return status in COURSE_TONES ? (status as CourseState) : "draft";
}
