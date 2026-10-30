import type { Locale } from "@yekkola/i18n";
import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { getTranslations, setRequestLocale } from "next-intl/server";

import { CheckoutForm } from "@/features/checkout/components/checkout-form";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;
  const t = await getTranslations({ locale: locale as Locale, namespace: "web.checkout" });

  return { title: t("title") };
}

/** Checkout for one course (`/checkout?course={id}`) or a bundle (`/checkout?bundle={id}`) (PRD-05). */
export default async function CheckoutPage({
  params,
  searchParams,
}: {
  params: Promise<{ locale: string }>;
  searchParams: Promise<{ course?: string | string[]; bundle?: string | string[] }>;
}) {
  setRequestLocale((await params).locale as Locale);
  const { course, bundle } = await searchParams;
  const isId = (v: unknown): v is string => typeof v === "string" && /^[0-9A-Za-z]{26}$/.test(v);
  if (isId(bundle)) return <CheckoutForm bundleId={bundle} />;
  if (!isId(course)) notFound();

  return <CheckoutForm courseId={course} />;
}
