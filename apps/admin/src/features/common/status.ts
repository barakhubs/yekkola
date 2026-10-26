import type { StatusTone } from "@yekkola/ui/blocks/status-badge";

export type ApplicationStatus =
  "submitted" | "under_review" | "needs_info" | "approved" | "rejected" | "expired";

export const APPLICATION_TONES: Record<ApplicationStatus, StatusTone> = {
  submitted: "warning",
  under_review: "info",
  needs_info: "accent",
  approved: "success",
  rejected: "danger",
  expired: "neutral",
};

/** Not decided yet: waiting, or taken on by a reviewer (the API's `filter[status]` takes a comma list). */
export const PENDING_STATUSES = "submitted,under_review";

export const APPLICATION_STATUSES = Object.keys(APPLICATION_TONES) as ApplicationStatus[];

export function applicationStatusOf(status: string): ApplicationStatus {
  return status in APPLICATION_TONES ? (status as ApplicationStatus) : "submitted";
}

export type VersionState = "in_review" | "approved" | "rejected" | "superseded";

export const VERSION_TONES: Record<VersionState, StatusTone> = {
  in_review: "warning",
  approved: "success",
  rejected: "danger",
  superseded: "neutral",
};

export const VERSION_STATES = Object.keys(VERSION_TONES) as VersionState[];

export function versionStateOf(state: string): VersionState {
  return state in VERSION_TONES ? (state as VersionState) : "in_review";
}
