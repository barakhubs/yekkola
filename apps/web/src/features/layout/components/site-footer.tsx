import { getTranslations } from "next-intl/server";

import { Link } from "@/i18n/navigation";
import { getBranding } from "@/lib/public-api";

export async function SiteFooter() {
  const t = await getTranslations();
  const branding = await getBranding();
  const logo = branding.footer_logo_url ?? branding.logo_url;

  return (
    <footer className="border-t">
      <div className="mx-auto flex max-w-6xl flex-col gap-1 px-4 py-8 text-sm text-muted-foreground">
        {logo ? (
          // eslint-disable-next-line @next/next/no-img-element -- uploaded by staff, any size
          <img
            src={logo}
            alt={t("common.appName")}
            className="mb-2 h-8 w-auto max-w-40 object-contain object-left"
          />
        ) : null}
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
