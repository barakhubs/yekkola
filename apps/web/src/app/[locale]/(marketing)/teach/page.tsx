import type { Locale } from "@yekkola/i18n";
import { Button } from "@yekkola/ui/components/button";
import { BadgeCheck, MapPinned, ShieldCheck, Smartphone } from "lucide-react";
import type { Metadata } from "next";
import { getTranslations, setRequestLocale } from "next-intl/server";
import type { ReactNode } from "react";

import { Link } from "@/i18n/navigation";
import { localeAlternates } from "@/lib/seo";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;
  const t = await getTranslations({ locale: locale as Locale, namespace: "web.teach" });

  return {
    title: t("metaTitle"),
    description: t("subtitle"),
    alternates: localeAlternates("/teach", locale as Locale),
  };
}

const BENEFITS = [
  { key: "reach", icon: <MapPinned aria-hidden className="size-6" /> },
  { key: "earn", icon: <Smartphone aria-hidden className="size-6" /> },
  { key: "protect", icon: <ShieldCheck aria-hidden className="size-6" /> },
  { key: "trust", icon: <BadgeCheck aria-hidden className="size-6" /> },
] as const;

const STEPS = ["apply", "review", "create", "publish"] as const;
const REQUIREMENTS = ["expertise", "experience", "material", "phone"] as const;

/**
 * "Teach on Yekkola" (PRD-02 §6): why, how it works, what it takes, and the way in. Static and light, like the
 * rest of the public site; the application itself is behind sign-in (`/studio/apply`).
 */
export default async function TeachPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;
  setRequestLocale(locale as Locale);
  const t = await getTranslations("web.teach");

  return (
    <div className="grid gap-14 pb-16">
      <section className="bg-primary text-primary-foreground">
        <div className="mx-auto grid max-w-4xl gap-5 px-4 py-14 text-center sm:py-20">
          <h1 className="text-3xl font-extrabold sm:text-5xl">{t("title")}</h1>
          <p className="mx-auto max-w-2xl text-lg opacity-90">{t("subtitle")}</p>
          <div>
            <Button asChild size="lg" variant="accent">
              <Link href="/studio/apply">{t("cta")}</Link>
            </Button>
          </div>
        </div>
      </section>

      <Block title={t("whyTitle")}>
        <ul className="grid gap-4 sm:grid-cols-2">
          {BENEFITS.map(({ key, icon }) => (
            <li key={key} className="flex gap-4 rounded-xl border p-5">
              <span className="text-primary">{icon}</span>
              <span className="grid gap-1">
                <span className="font-bold">{t(`benefits.${key}.title`)}</span>
                <span className="text-sm text-muted-foreground">{t(`benefits.${key}.body`)}</span>
              </span>
            </li>
          ))}
        </ul>
      </Block>

      <Block title={t("howTitle")}>
        <ol className="grid gap-4 sm:grid-cols-4">
          {STEPS.map((key, index) => (
            <li key={key} className="grid content-start gap-2 rounded-xl border p-5">
              <span
                aria-hidden
                className="grid size-9 place-items-center rounded-full bg-brand-accent font-extrabold text-brand-accent-foreground"
              >
                {index + 1}
              </span>
              <span className="font-bold">{t(`steps.${key}.title`)}</span>
              <span className="text-sm text-muted-foreground">{t(`steps.${key}.body`)}</span>
            </li>
          ))}
        </ol>
      </Block>

      <Block title={t("needTitle")}>
        <ul className="grid list-disc gap-2 ps-5">
          {REQUIREMENTS.map((key) => (
            <li key={key}>{t(`requirements.${key}`)}</li>
          ))}
        </ul>
      </Block>

      <section className="mx-auto grid max-w-4xl justify-items-center gap-4 px-4 text-center">
        <h2 className="text-2xl font-extrabold">{t("finalTitle")}</h2>
        <p className="text-muted-foreground">{t("finalBody")}</p>
        <Button asChild size="lg">
          <Link href="/studio/apply">{t("cta")}</Link>
        </Button>
      </section>
    </div>
  );
}

function Block({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="mx-auto grid w-full max-w-5xl gap-5 px-4">
      <h2 className="text-2xl font-extrabold">{title}</h2>
      {children}
    </section>
  );
}
