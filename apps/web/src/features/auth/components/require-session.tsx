"use client";

import { Skeleton } from "@yekkola/ui/components/skeleton";
import { useSearchParams } from "next/navigation";
import { type ReactNode, useEffect } from "react";

import { usePathname, useRouter } from "@/i18n/navigation";

import { useSession } from "../hooks/use-session";
import { SessionUnavailable, SuspendedNotice } from "./account-states";

/**
 * Signed-in areas. Visitors (401) go to sign-in and come back here; accounts without a name finish their
 * profile first (FR-04); suspended accounts see why (FR-15). The API enforces access regardless.
 */
export function RequireSession({ children }: { children: ReactNode }) {
  const session = useSession();
  const router = useRouter();
  const pathname = usePathname();
  const query = useSearchParams().toString();
  const here = query ? `${pathname}?${query}` : pathname;

  useEffect(() => {
    if (session.isSignedOut) {
      router.replace(`/login?next=${encodeURIComponent(here)}`);
    } else if (session.needsProfile && !session.isSuspended) {
      router.replace(`/login?step=profile&next=${encodeURIComponent(here)}`);
    }
  }, [session.isSignedOut, session.needsProfile, session.isSuspended, here, router]);

  if (session.isError) {
    return <SessionUnavailable onRetry={session.retry} />;
  }
  if (session.isSuspended) {
    return <SuspendedNotice />;
  }
  if (!session.isSignedIn || session.needsProfile) {
    return (
      <div className="mx-auto grid max-w-6xl gap-4 px-4 py-10" aria-busy>
        <Skeleton className="h-8 w-48" />
        <Skeleton className="h-32 w-full" />
      </div>
    );
  }

  return <>{children}</>;
}
