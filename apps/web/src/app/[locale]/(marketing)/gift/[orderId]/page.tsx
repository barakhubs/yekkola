import type { Locale } from "@yekkola/i18n";
import type { Metadata } from "next";
import { setRequestLocale } from "next-intl/server";

import { GuestOrderStatus } from "@/features/checkout/components/guest-checkout";

/** A guest order's payment steps; the order token is in the address after `#`. */
export const metadata: Metadata = { robots: { index: false, follow: false } };

export default async function GiftOrderPage({
  params,
}: {
  params: Promise<{ locale: string; orderId: string }>;
}) {
  const { locale, orderId } = await params;
  setRequestLocale(locale as Locale);

  return <GuestOrderStatus orderId={orderId} />;
}
