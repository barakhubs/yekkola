import type { StatusTone } from "@yekkola/ui/blocks/status-badge";

export type ProfessorState = "active" | "suspended";
export type KycState = "none" | "pending" | "verified" | "rejected";

export const PROFESSOR_TONES: Record<ProfessorState, StatusTone> = {
  active: "success",
  suspended: "warning",
};

export const KYC_TONES: Record<KycState, StatusTone> = {
  none: "neutral",
  pending: "info",
  verified: "success",
  rejected: "danger",
};

export const PROFESSOR_STATES = Object.keys(PROFESSOR_TONES) as ProfessorState[];
export const KYC_STATES = Object.keys(KYC_TONES) as KycState[];

export function professorStateOf(status: string): ProfessorState {
  return status in PROFESSOR_TONES ? (status as ProfessorState) : "active";
}

export function kycStateOf(status: string): KycState {
  return status in KYC_TONES ? (status as KycState) : "none";
}
