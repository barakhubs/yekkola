"use client";

import { Button } from "@yekkola/ui/components/button";
import { LogOut } from "lucide-react";
import { useSearchParams } from "next/navigation";
import { useTranslations } from "next-intl";

import { useSignOut } from "@/features/auth/components/account-states";
import { useSession } from "@/features/auth/hooks/use-session";
import { Link, usePathname } from "@/i18n/navigation";

/** Header actions: "Sign in" for visitors; "Studio" (professors), "My courses" and "Sign out" once signed in. */
export function AccountNav() {
  const t = useTranslations("web");
  const { user, isSignedIn, isSuspended, isLoading } = useSession();
  const pathname = usePathname();
  const query = useSearchParams().toString();
  const signOut = useSignOut();

  if (isLoading) {
    return <span className="h-8 w-24" aria-hidden />;
  }

  if (!isSignedIn) {
    const here = query ? `${pathname}?${query}` : pathname;
    return (
      <Button asChild size="sm">
        <Link href={pathname === "/login" ? "/login" : `/login?next=${encodeURIComponent(here)}`}>
          {t("nav.signIn")}
        </Link>
      </Button>
    );
  }

  return (
    <>
      {isSuspended || !user?.roles.includes("professor") ? null : (
        <Button asChild size="sm" variant="outline" className="hidden sm:inline-flex">
          <Link href="/studio/courses">{t("nav.studio")}</Link>
        </Button>
      )}
      {isSuspended ? null : (
        <Button asChild size="sm">
          <Link href="/my-courses">{t("nav.myCourses")}</Link>
        </Button>
      )}
      {/* An icon on phones so the signed-in header fits 360 px; the label stays for screen readers. */}
      <Button variant="ghost" size="sm" onClick={() => signOut.mutate()} disabled={signOut.isPending}>
        <LogOut aria-hidden className="sm:hidden" />
        <span className="sr-only sm:not-sr-only">{t("auth.signOut")}</span>
      </Button>
    </>
  );
}
