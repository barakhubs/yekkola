import { getTranslations } from "next-intl/server";

import { Link } from "@/i18n/navigation";

export async function SiteFooter() {
  const t = await getTranslations();

  return (
    <footer className="border-t">
      <div className="mx-auto flex max-w-6xl flex-col gap-1 px-4 py-8 text-sm text-muted-foreground">
        <p className="font-semibold text-foreground">{t("common.tagline")}</p>
        <p>
          <Link href="/teach" className="font-semibold text-primary hover:underline">
            {t("web.nav.teach")}
          </Link>
        </p>
        <p>{t("web.footer.rights", { year: new Date().getFullYear() })}</p>
      </div>
    </footer>
  );
}
