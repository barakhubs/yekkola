"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { authLogout } from "@yekkola/api-client/endpoints/logout";
import { Button } from "@yekkola/ui/components/button";
import { useTranslations } from "next-intl";

import { useSession } from "@/features/auth/hooks/use-session";
import { Link, usePathname, useRouter } from "@/i18n/navigation";

/** Header actions: "Sign in" for visitors; "My courses" and "Sign out" once signed in. */
export function AccountNav() {
  const t = useTranslations("web");
  const { isSignedIn, isLoading } = useSession();
  const pathname = usePathname();
  const router = useRouter();
  const queryClient = useQueryClient();
  const signOut = useMutation({
    mutationFn: () => authLogout(),
    onSettled: async () => {
      queryClient.clear();
      router.replace("/");
    },
  });

  if (isLoading) {
    return <span className="h-8 w-24" aria-hidden />;
  }

  if (!isSignedIn) {
    return (
      <Button asChild size="sm">
        <Link href={pathname === "/login" ? "/login" : `/login?next=${encodeURIComponent(pathname)}`}>
          {t("nav.signIn")}
        </Link>
      </Button>
    );
  }

  return (
    <>
      <Button asChild size="sm">
        <Link href="/my-courses">{t("nav.myCourses")}</Link>
      </Button>
      <Button variant="ghost" size="sm" onClick={() => signOut.mutate()} disabled={signOut.isPending}>
        {t("auth.signOut")}
      </Button>
    </>
  );
}
