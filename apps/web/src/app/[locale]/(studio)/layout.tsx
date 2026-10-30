import type { Locale } from "@yekkola/i18n";
import type { Metadata } from "next";
import { setRequestLocale } from "next-intl/server";
import { type ReactNode, Suspense } from "react";

import { RequireSession } from "@/features/auth/components/require-session";
import { SiteHeader } from "@/features/layout/components/site-header";

/** Professor tools are never indexed. */
export const metadata: Metadata = { robots: { index: false, follow: false } };

/** Professor studio: client pages behind the session guard; course pages also need the professor role (their layout). */
export default async function StudioLayout({
  children,
  params,
}: {
  children: ReactNode;
  params: Promise<{ locale: string }>;
}) {
  setRequestLocale((await params).locale as Locale);

  return (
    <div className="flex min-h-dvh flex-col bg-muted/30">
      <SiteHeader />
      <main id="content" className="flex-1">
        <Suspense>
          <RequireSession>{children}</RequireSession>
        </Suspense>
      </main>
    </div>
  );
}
