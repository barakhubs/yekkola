import type { Locale } from "@yekkola/i18n";
import { Button } from "@yekkola/ui/components/button";
import { Input } from "@yekkola/ui/components/input";
import { Search } from "lucide-react";
import { getTranslations } from "next-intl/server";

import { getPathname } from "@/i18n/navigation";

/** Catalogue search (PRD-04 FR-03): a plain GET form, so it works before any JavaScript loads. */
export async function SearchForm({ locale, defaultValue }: { locale: Locale; defaultValue?: string }) {
  const t = await getTranslations("web.search");

  return (
    <form
      action={getPathname({ href: "/courses", locale })}
      method="get"
      role="search"
      className="flex w-full gap-2"
    >
      <label htmlFor="catalogue-search" className="sr-only">
        {t("label")}
      </label>
      <Input
        id="catalogue-search"
        name="q"
        type="search"
        defaultValue={defaultValue}
        placeholder={t("placeholder")}
        maxLength={100}
        enterKeyHint="search"
        className="h-11 bg-background"
      />
      <Button type="submit" size="lg" className="h-11" aria-label={t("submit")}>
        <Search aria-hidden />
        <span className="hidden sm:inline">{t("submit")}</span>
      </Button>
    </form>
  );
}
