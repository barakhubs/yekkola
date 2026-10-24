import type { Locale } from "@yekkola/i18n";
import type { Metadata } from "next";
import { getTranslations, setRequestLocale } from "next-intl/server";
import { Suspense } from "react";

import { SignInFlow } from "@/features/auth/components/sign-in-flow";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;
  const t = await getTranslations({ locale: locale as Locale, namespace: "web.auth" });

  return { title: t("title"), robots: { index: false, follow: false } };
}

/** Phone + SMS code sign-in (PRD-01). */
export default async function LoginPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;
  setRequestLocale(locale as Locale);
  const t = await getTranslations("web.auth");

  return (
    <div className="mx-auto grid w-full max-w-md gap-6 px-4 py-10 sm:py-16">
      <div className="grid gap-2">
        <h1 className="text-2xl font-extrabold">{t("title")}</h1>
        <p className="text-muted-foreground">{t("subtitle")}</p>
      </div>
      <div className="rounded-xl border bg-card p-5 shadow-sm">
        <Suspense>
          <SignInFlow />
        </Suspense>
      </div>
    </div>
  );
}
