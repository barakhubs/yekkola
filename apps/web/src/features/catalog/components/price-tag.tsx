import type { Locale } from "@yekkola/i18n";
import { MoneyText } from "@yekkola/ui/blocks/money-text";
import { Badge } from "@yekkola/ui/components/badge";
import { getTranslations } from "next-intl/server";

interface PriceTagProps {
  isFree: boolean;
  price: { amount_minor: number; currency: string } | null;
  locale: Locale;
  className?: string;
}

/** "Gratuit" badge (amber, indigo text) or the price from the API — never computed here. */
export async function PriceTag({ isFree, price, locale, className }: PriceTagProps) {
  const t = await getTranslations("common");

  if (isFree || price === null) {
    return (
      <Badge variant="accent" className={className}>
        {t("free")}
      </Badge>
    );
  }

  return <MoneyText value={price} locale={locale} className={className ?? "font-bold"} />;
}
