import { Button } from "@yekkola/ui/components/button";
import { getTranslations } from "next-intl/server";
import { Suspense } from "react";

import { ImpersonationBanner } from "@/features/auth/components/impersonation-banner";
import { Link } from "@/i18n/navigation";

import { AccountNav } from "./account-nav";
import { LocaleSwitcher } from "./locale-switcher";

export async function SiteHeader() {
  const t = await getTranslations("web.nav");
  const common = await getTranslations("common");

  return (
    <header className="border-b bg-background">
      <ImpersonationBanner />
      <div className="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4">
        <Link href="/" className="text-xl font-extrabold tracking-wide text-primary">
          {common("appName")}
        </Link>
        <nav className="flex items-center gap-1 sm:gap-2">
          <Button asChild variant="ghost" size="sm">
            <Link href="/courses">{t("courses")}</Link>
          </Button>
          <Suspense>
            <LocaleSwitcher />
          </Suspense>
          <Suspense>
            <AccountNav />
          </Suspense>
        </nav>
      </div>
    </header>
  );
}
