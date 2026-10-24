import { buttonVariants } from "@yekkola/ui/components/button";
import { cn } from "@yekkola/ui/lib/utils";
import { getTranslations } from "next-intl/server";

import { Link } from "@/i18n/navigation";

interface PaginationProps {
  page: number;
  lastPage: number;
  /** Builds the link for a page number (keeps the current filters). */
  hrefFor: (page: number) => string;
}

/** Previous / next links (crawlable) with the current position. */
export async function Pagination({ page, lastPage, hrefFor }: PaginationProps) {
  if (lastPage <= 1) {
    return null;
  }
  const t = await getTranslations("web.pagination");

  return (
    <nav aria-label={t("label")} className="flex items-center justify-between gap-4">
      {page > 1 ? (
        <Link href={hrefFor(page - 1)} rel="prev" className={buttonVariants({ variant: "outline" })}>
          {t("previous")}
        </Link>
      ) : (
        <span />
      )}
      <span className="text-sm text-muted-foreground">{t("page", { page, total: lastPage })}</span>
      {page < lastPage ? (
        <Link href={hrefFor(page + 1)} rel="next" className={cn(buttonVariants({ variant: "outline" }))}>
          {t("next")}
        </Link>
      ) : (
        <span />
      )}
    </nav>
  );
}
