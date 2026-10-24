import { formatDate, type Locale } from "@yekkola/i18n";
import { EmptyState } from "@yekkola/ui/blocks/empty-state";
import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { getTranslations, setRequestLocale } from "next-intl/server";

import { CourseGrid } from "@/features/catalog/components/course-grid";
import { ShareButtons } from "@/features/course/components/share-buttons";
import { ProfessorSummary } from "@/features/professor/components/professor-summary";
import { getPathname } from "@/i18n/navigation";
import { JsonLd, professorJsonLd } from "@/lib/json-ld";
import { getProfessor } from "@/lib/public-api";
import { localeAlternates } from "@/lib/seo";

interface Props {
  params: Promise<{ locale: string; slug: string }>;
}

export const revalidate = 300;

export function generateStaticParams() {
  return [];
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { locale, slug } = await params;
  const professor = await getProfessor(slug);
  if (!professor) {
    return {};
  }

  return {
    title: professor.display_name,
    description: professor.headline ?? professor.bio?.slice(0, 160) ?? undefined,
    alternates: localeAlternates(`/professors/${slug}`, locale as Locale),
    openGraph: {
      type: "profile",
      images: professor.avatar_url ? [{ url: professor.avatar_url }] : undefined,
    },
  };
}

/** A professor's public page with their listed courses (PRD-04 FR-07, PRD-02 FR-12). */
export default async function ProfessorPage({ params }: Props) {
  const { locale: rawLocale, slug } = await params;
  const locale = rawLocale as Locale;
  setRequestLocale(locale);
  const professor = await getProfessor(slug);
  if (!professor) {
    notFound();
  }
  const t = await getTranslations("web");
  const siteUrl = (process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000").replace(/\/$/, "");

  return (
    <div className="mx-auto grid max-w-6xl gap-10 px-4 py-8 sm:py-12">
      <JsonLd data={professorJsonLd(professor, locale)} />
      <header className="grid gap-4">
        <ProfessorSummary professor={professor} variant="header" />
        <p className="text-sm text-muted-foreground">
          {t("professor.memberSince", { date: formatDate(professor.member_since, locale, "long") })}
        </p>
        <ShareButtons
          url={`${siteUrl}${getPathname({ href: `/professors/${slug}`, locale })}`}
          text={professor.display_name}
        />
      </header>

      {professor.bio ? (
        <section aria-labelledby="about" className="grid max-w-3xl gap-2">
          <h2 id="about" className="text-xl font-bold">
            {t("professor.about")}
          </h2>
          <p className="whitespace-pre-line">{professor.bio}</p>
        </section>
      ) : null}

      <section aria-labelledby="courses" className="grid gap-4">
        <h2 id="courses" className="text-xl font-bold">
          {t("professor.courses", { name: professor.display_name })}
        </h2>
        {professor.courses && professor.courses.length > 0 ? (
          <CourseGrid courses={professor.courses} locale={locale} />
        ) : (
          <EmptyState title={t("professor.noCourses")} />
        )}
      </section>
    </div>
  );
}
