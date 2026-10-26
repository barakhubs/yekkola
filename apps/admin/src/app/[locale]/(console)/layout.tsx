import type { Locale } from "@yekkola/i18n";
import { setRequestLocale } from "next-intl/server";
import { type ReactNode, Suspense } from "react";

import { ConsoleShell } from "@/features/shell/console-shell";

/** Staff console: client pages behind the staff guard, with the sidebar (architecture §5.3). */
export default async function ConsoleLayout({
  children,
  params,
}: {
  children: ReactNode;
  params: Promise<{ locale: string }>;
}) {
  setRequestLocale((await params).locale as Locale);

  return (
    <Suspense>
      <ConsoleShell>{children}</ConsoleShell>
    </Suspense>
  );
}
