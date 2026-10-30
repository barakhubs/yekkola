import type { ReactNode } from "react";

import { RequireProfessor } from "@/features/studio/components/require-professor";

/** Course tools are for approved professors; everyone else is pointed to the application (`/studio/apply`). */
export default function StudioCoursesLayout({ children }: { children: ReactNode }) {
  return <RequireProfessor>{children}</RequireProfessor>;
}
