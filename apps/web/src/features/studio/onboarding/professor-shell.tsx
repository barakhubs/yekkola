"use client";

import { useMutation } from "@tanstack/react-query";
import { ApiError, success } from "@yekkola/api-client";
import { professorTermsAccept, useProfessorProfileShow } from "@yekkola/api-client/endpoints/studio-profile";
import { EmptyState } from "@yekkola/ui/blocks/empty-state";
import { Button } from "@yekkola/ui/components/button";
import { Skeleton } from "@yekkola/ui/components/skeleton";
import { cn } from "@yekkola/ui/lib/utils";
import { FileSignature } from "lucide-react";
import { useTranslations } from "next-intl";
import type { ReactNode } from "react";

import { Link, usePathname } from "@/i18n/navigation";

import { RequireProfessor } from "../components/require-professor";
import { useErrorText } from "../hooks";

const NAV = [
  { href: "/studio", key: "home" },
  { href: "/studio/courses", key: "courses" },
  { href: "/studio/profile", key: "profile" },
  { href: "/studio/verification", key: "kyc" },
  { href: "/studio/payouts", key: "payouts" },
] as const;

/** The professor's own profile: onboarding status, terms, payout method (PRD-02). */
export function useStudioProfile() {
  return useProfessorProfileShow({
    query: {
      select: (r) => success(r).data,
      retry: (failures, error) => !(error instanceof ApiError && error.status < 500) && failures < 2,
    },
  });
}

/**
 * Frame for professor pages: professors only, then the agreement gate — when the professor agreement changes, the
 * API refuses studio requests until it is accepted again (PRD-02 FR-10) — then the studio nav.
 */
export function ProfessorShell({ children }: { children: ReactNode }) {
  return (
    <RequireProfessor>
      <TermsGate>
        <StudioNav />
        {children}
      </TermsGate>
    </RequireProfessor>
  );
}

function TermsGate({ children }: { children: ReactNode }) {
  const t = useTranslations("web.onboarding");
  const errorText = useErrorText();
  const profile = useStudioProfile();
  const version =
    profile.error instanceof ApiError && profile.error.code === "terms.acceptance_required"
      ? String(profile.error.body.version ?? "")
      : null;
  const accept = useMutation({
    // Echo back the version the API asked for (the generated type pins today's version as a literal).
    mutationFn: async (v: string) =>
      success(await professorTermsAccept({ version: v } as Parameters<typeof professorTermsAccept>[0])),
    onSuccess: () => profile.refetch(),
  });

  if (profile.isPending) {
    return (
      <div className="mx-auto grid max-w-5xl gap-4 px-4 py-8">
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-40 w-full" />
      </div>
    );
  }

  if (version) {
    return (
      <div className="mx-auto max-w-xl px-4 py-16">
        <EmptyState
          icon={<FileSignature />}
          title={t("termsTitle")}
          description={t("termsBody", { version })}
          action={
            <div className="grid justify-items-center gap-2">
              <Button
                onClick={() => accept.mutate(version)}
                disabled={accept.isPending}
                aria-busy={accept.isPending}
              >
                {t("termsAccept")}
              </Button>
              {accept.isError ? (
                <p role="alert" className="text-sm text-destructive">
                  {errorText(accept.error)}
                </p>
              ) : null}
            </div>
          }
        />
      </div>
    );
  }

  return <>{children}</>;
}

function StudioNav() {
  const t = useTranslations("web.onboarding.nav");
  const pathname = usePathname();
  const isCurrent = (href: string) =>
    href === "/studio" ? pathname === "/studio" : pathname.startsWith(href);

  return (
    <nav aria-label={t("label")} className="border-b bg-background">
      <ul className="mx-auto flex max-w-5xl gap-1 overflow-x-auto px-4">
        {NAV.map(({ href, key }) => (
          <li key={href}>
            <Link
              href={href}
              aria-current={isCurrent(href) ? "page" : undefined}
              className={cn(
                "-mb-px inline-block border-b-2 px-3 py-3 text-sm font-semibold whitespace-nowrap",
                isCurrent(href)
                  ? "border-primary text-primary"
                  : "border-transparent text-muted-foreground hover:text-foreground",
              )}
            >
              {t(key)}
            </Link>
          </li>
        ))}
      </ul>
    </nav>
  );
}
