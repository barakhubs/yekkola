import type { Locale } from "@yekkola/i18n";
import type { Metadata } from "next";
import { getTranslations, setRequestLocale } from "next-intl/server";

import { ChangePassword } from "@/features/auth/password-sign-in";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;
  const t = await getTranslations({ locale: locale as Locale, namespace: "admin" });

  return { title: t("auth.changeTitle") };
}

/** A staff member's own back-office password. */
export default async function Page({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;
  setRequestLocale(locale as Locale);

  return (
    <div className="mx-auto max-w-6xl px-4 py-8 sm:px-6">
      <ChangePassword required={false} />
    </div>
  );
}
