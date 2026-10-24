"use client";

import { Button } from "@yekkola/ui/components/button";
import { useSearchParams } from "next/navigation";
import { useTranslations } from "next-intl";

import { useSignOut } from "@/features/auth/components/account-states";
import { useSession } from "@/features/auth/hooks/use-session";
import { Link, usePathname } from "@/i18n/navigation";

/** Header actions: "Sign in" for visitors; "My courses" and "Sign out" once signed in. */
export function AccountNav() {
  const t = useTranslations("web");
  const { isSignedIn, isSuspended, isLoading } = useSession();
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
      {isSuspended ? null : (
        <Button asChild size="sm">
          <Link href="/my-courses">{t("nav.myCourses")}</Link>
        </Button>
      )}
      <Button variant="ghost" size="sm" onClick={() => signOut.mutate()} disabled={signOut.isPending}>
        {t("auth.signOut")}
      </Button>
    </>
  );
}
