import type { Locale } from "@yekkola/i18n";
import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { getTranslations, setRequestLocale } from "next-intl/server";

import { Link } from "@/i18n/navigation";

const DOCS = ["terms", "privacy", "professor-agreement"] as const;
type Doc = (typeof DOCS)[number];
const KEYS: Record<Doc, "terms" | "privacy" | "professorAgreement"> = {
  terms: "terms",
  privacy: "privacy",
  "professor-agreement": "professorAgreement",
};

export function generateStaticParams() {
  return DOCS.map((doc) => ({ doc }));
}

function isDoc(value: string): value is Doc {
  return (DOCS as readonly string[]).includes(value);
}

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string; doc: string }>;
}): Promise<Metadata> {
  const { locale, doc } = await params;
  if (!isDoc(doc)) return {};
  const t = await getTranslations({ locale: locale as Locale, namespace: "web.legal" });

  // Drafts stay out of search results until counsel's texts replace them.
  return { title: t(`${KEYS[doc]}.title`), robots: { index: false } };
}

/**
 * Terms, privacy policy and professor agreement. Placeholders: the binding texts come from counsel before launch
 * (project-context: Open decisions, legal). Each states what the product already does, in plain words.
 */
export default async function LegalPage({ params }: { params: Promise<{ locale: string; doc: string }> }) {
  const { locale, doc } = await params;
  if (!isDoc(doc)) notFound();
  setRequestLocale(locale as Locale);
  const t = await getTranslations({ locale: locale as Locale, namespace: "web.legal" });
  const key = KEYS[doc];
  const points = t.raw(`${key}.points`) as string[];

  return (
    <article className="mx-auto grid max-w-3xl gap-6 px-4 py-10">
      <h1 className="text-3xl font-extrabold">{t(`${key}.title`)}</h1>
      <p
        role="note"
        className="rounded-md border border-brand-accent bg-amber-50 px-4 py-3 text-sm dark:bg-indigo-950"
      >
        {t("draftNotice")}
      </p>
      <p>{t(`${key}.summary`)}</p>
      <ul className="grid list-disc gap-2 pl-6">
        {points.map((point) => (
          <li key={point}>{point}</li>
        ))}
      </ul>
      <nav aria-label={t("otherDocs")} className="flex flex-wrap gap-4 text-sm">
        {DOCS.filter((d) => d !== doc).map((d) => (
          <Link key={d} href={`/legal/${d}`} className="font-semibold text-primary hover:underline">
            {t(`${KEYS[d]}.title`)}
          </Link>
        ))}
      </nav>
    </article>
  );
}
