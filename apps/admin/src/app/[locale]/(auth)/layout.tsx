import type { Locale } from "@yekkola/i18n";
import { getTranslations, setRequestLocale } from "next-intl/server";
import type { ReactNode } from "react";

import { getBranding } from "@/lib/branding.server";

/** Sign-in frame: the brand and a card, nothing else. */
export default async function AuthLayout({
  children,
  params,
}: {
  children: ReactNode;
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;
  setRequestLocale(locale as Locale);
  const t = await getTranslations("admin");
  const { logo_url } = await getBranding();

  return (
    <main id="content" className="grid min-h-dvh place-items-center bg-muted/30 px-4 py-10">
      <div className="grid w-full max-w-sm gap-6">
        {logo_url ? (
          // eslint-disable-next-line @next/next/no-img-element -- uploaded by staff, any storage host
          <img src={logo_url} alt={t("brand")} className="mx-auto h-10 w-auto max-w-56 object-contain" />
        ) : (
          <p className="text-center text-xl font-extrabold tracking-wide text-primary">{t("brand")}</p>
        )}
        <div className="rounded-xl border bg-background p-6">{children}</div>
      </div>
    </main>
  );
}
