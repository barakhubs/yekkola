"use client";

import { EmptyState } from "@yekkola/ui/blocks/empty-state";
import { Button } from "@yekkola/ui/components/button";
import { GraduationCap } from "lucide-react";
import { useTranslations } from "next-intl";
import type { ReactNode } from "react";

import { useSession } from "@/features/auth/hooks/use-session";
import { Link } from "@/i18n/navigation";

/**
 * The studio is for approved professors (PRD-02). Others see why instead of a wall of 403s; the API enforces
 * access on every request regardless (and explains a missing agreement or a suspension in its errors).
 */
export function RequireProfessor({ children }: { children: ReactNode }) {
  const t = useTranslations("web.studio");
  const { user } = useSession();

  if (!user?.roles.includes("professor")) {
    return (
      <div className="mx-auto max-w-xl px-4 py-16">
        <EmptyState
          icon={<GraduationCap />}
          title={t("notProfessorTitle")}
          description={t("notProfessorBody")}
          action={
            <Button asChild variant="outline">
              <Link href="/my-courses">{t("backToLearning")}</Link>
            </Button>
          }
        />
      </div>
    );
  }

  return <>{children}</>;
}
