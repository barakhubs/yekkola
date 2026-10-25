import type { Locale } from "@yekkola/i18n";
import type { Metadata } from "next";
import { getTranslations, setRequestLocale } from "next-intl/server";

import { PaymentStatus } from "@/features/checkout/components/payment-status";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;
  const t = await getTranslations({ locale: locale as Locale, namespace: "web.checkout" });

  return { title: t("title") };
}

/** Waiting screen and result for an order (PRD-05 FR-06–FR-09). */
export default async function OrderStatusPage({
  params,
}: {
  params: Promise<{ locale: string; orderId: string }>;
}) {
  const { locale, orderId } = await params;
  setRequestLocale(locale as Locale);

  return <PaymentStatus orderId={orderId} />;
}
