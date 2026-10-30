import type { ReactNode } from "react";

import { ProfessorShell } from "@/features/studio/onboarding/professor-shell";

/**
 * Everything for approved professors: the professor guard, the agreement gate and the studio nav. Others are
 * pointed to the application (`/studio/apply`, outside this group).
 */
export default function ProfessorLayout({ children }: { children: ReactNode }) {
  return <ProfessorShell>{children}</ProfessorShell>;
}
