import type { Locale } from "@yekkola/i18n";
import type { Metadata } from "next";
import { getTranslations, setRequestLocale } from "next-intl/server";

import { OrderList } from "@/features/checkout/components/order-list";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;
  const t = await getTranslations({ locale: locale as Locale, namespace: "web.orders" });

  return { title: t("title") };
}

/** My orders and receipts (PRD-05 FR-17). */
export default async function OrdersPage({ params }: { params: Promise<{ locale: string }> }) {
  setRequestLocale((await params).locale as Locale);

  return <OrderList />;
}
