import type { Locale } from "@yekkola/i18n";
import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { getTranslations, setRequestLocale } from "next-intl/server";

import { GuestCheckout } from "@/features/checkout/components/guest-checkout";
import { getCourse } from "@/lib/public-api";

type Props = { params: Promise<{ locale: string; slug: string }> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { locale, slug } = await params;
  const t = await getTranslations({ locale: locale as Locale, namespace: "web.gift" });
  const course = await getCourse(slug);

  return { title: t("title", { course: course?.title ?? "" }), robots: { index: false, follow: false } };
}

/** Pay for a student's course without an account (PRD-05 FR-14), when the "separate payer" setting is on. */
export default async function GiftPage({ params }: Props) {
  const { locale, slug } = await params;
  setRequestLocale(locale as Locale);
  const course = await getCourse(slug);
  if (!course?.can_buy_for_others) notFound();

  return <GuestCheckout course={course} />;
}
