import type { StatusTone } from "@yekkola/ui/blocks/status-badge";

export type UserState = "active" | "suspended" | "banned";

export const USER_TONES: Record<UserState, StatusTone> = {
  active: "success",
  suspended: "warning",
  banned: "danger",
};

export const USER_STATES = Object.keys(USER_TONES) as UserState[];

export function userStateOf(status: string): UserState {
  return status in USER_TONES ? (status as UserState) : "active";
}

export const ROLES = ["student", "professor", "moderator", "admin", "super-admin"] as const;
