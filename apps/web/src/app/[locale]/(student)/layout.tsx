import type { Locale } from "@yekkola/i18n";
import type { Metadata } from "next";
import { setRequestLocale } from "next-intl/server";
import { type ReactNode, Suspense } from "react";

import { RequireSession } from "@/features/auth/components/require-session";
import { SiteFooter } from "@/features/layout/components/site-footer";
import { SiteHeader } from "@/features/layout/components/site-header";

/** Personal pages are never indexed. */
export const metadata: Metadata = { robots: { index: false, follow: false } };

/** Student area: client pages behind the session guard (architecture §5.2). */
export default async function StudentLayout({
  children,
  params,
}: {
  children: ReactNode;
  params: Promise<{ locale: string }>;
}) {
  setRequestLocale((await params).locale as Locale);

  return (
    <div className="flex min-h-dvh flex-col">
      <SiteHeader />
      <main id="content" className="flex-1">
        <Suspense>
          <RequireSession>{children}</RequireSession>
        </Suspense>
      </main>
      <SiteFooter />
    </div>
  );
}
