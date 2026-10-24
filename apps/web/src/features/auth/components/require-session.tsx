"use client";

import { Skeleton } from "@yekkola/ui/components/skeleton";
import { useEffect, type ReactNode } from "react";

import { usePathname, useRouter } from "@/i18n/navigation";

import { useSession } from "../hooks/use-session";

/** Signed-in areas: visitors go to sign-in and come back here afterwards. The API enforces access regardless. */
export function RequireSession({ children }: { children: ReactNode }) {
  const { isSignedIn, isLoading } = useSession();
  const router = useRouter();
  const pathname = usePathname();

  useEffect(() => {
    if (!isLoading && !isSignedIn) {
      router.replace(`/login?next=${encodeURIComponent(pathname)}`);
    }
  }, [isLoading, isSignedIn, pathname, router]);

  if (!isSignedIn) {
    return (
      <div className="mx-auto grid max-w-6xl gap-4 px-4 py-10" aria-busy>
        <Skeleton className="h-8 w-48" />
        <Skeleton className="h-32 w-full" />
      </div>
    );
  }

  return <>{children}</>;
}
