import { formatDate, type Locale } from "@yekkola/i18n";
import { Button } from "@yekkola/ui/components/button";
import { BadgeCheck, ShieldX } from "lucide-react";
import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { getTranslations, setRequestLocale } from "next-intl/server";

import { Link } from "@/i18n/navigation";
import { verifyCertificate } from "@/lib/public-api";
import { visitorHeaders } from "@/lib/visitor";

interface Props {
  params: Promise<{ locale: string; serial: string }>;
}

/** Checked live on every visit: a revocation shows at once. */
export const dynamic = "force-dynamic";

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { locale } = await params;
  const t = await getTranslations({ locale: locale as Locale, namespace: "web.certificate" });

  // Certificates name a person: never indexed.
  return { title: t("title"), robots: { index: false, follow: false } };
}

/** Public verification by serial (PRD-04 FR-09, PRD-06 FR-11): names as issued, valid or revoked. */
export default async function CertificatePage({ params }: Props) {
  const { locale: rawLocale, serial } = await params;
  const locale = rawLocale as Locale;
  setRequestLocale(locale);
  const t = await getTranslations("web.certificate");
  const certificate = /^[A-Za-z0-9-]{4,32}$/.test(serial)
    ? await verifyCertificate(serial, await visitorHeaders())
    : null;
  if (!certificate) {
    notFound();
  }

  const valid = certificate.status === "valid";

  return (
    <div className="mx-auto grid max-w-2xl gap-6 px-4 py-12">
      <h1 className="text-2xl font-extrabold">{t("title")}</h1>
      <section
        className={`grid gap-6 rounded-xl border-2 p-6 ${valid ? "border-success" : "border-destructive"}`}
        aria-labelledby="certificate-status"
      >
        <p
          id="certificate-status"
          className={`flex items-center gap-2 text-lg font-bold ${valid ? "text-success" : "text-destructive"}`}
        >
          {valid ? <BadgeCheck aria-hidden className="size-6" /> : <ShieldX aria-hidden className="size-6" />}
          {valid ? t("valid") : t("revoked")}
        </p>
        <dl className="grid gap-4 sm:grid-cols-2">
          <div>
            <dt className="text-sm text-muted-foreground">{t("awardedTo")}</dt>
            <dd className="text-lg font-bold">{certificate.student_name}</dd>
          </div>
          <div>
            <dt className="text-sm text-muted-foreground">{t("course")}</dt>
            <dd className="font-bold">{certificate.course_title}</dd>
          </div>
          <div>
            <dt className="text-sm text-muted-foreground">{t("professor")}</dt>
            <dd>{certificate.professor_name}</dd>
          </div>
          <div>
            <dt className="text-sm text-muted-foreground">{t("issuedOn")}</dt>
            <dd>
              <time dateTime={certificate.issued_at}>
                {formatDate(certificate.issued_at, locale, "long")}
              </time>
            </dd>
          </div>
          <div>
            <dt className="text-sm text-muted-foreground">{t("serial")}</dt>
            <dd className="font-mono">{certificate.serial}</dd>
          </div>
          {certificate.revoked_at ? (
            <div>
              <dt className="sr-only">{t("revoked")}</dt>
              <dd className="text-destructive">
                {t("revokedOn", { date: formatDate(certificate.revoked_at, locale, "long") })}
              </dd>
            </div>
          ) : null}
        </dl>
        {certificate.course_slug ? (
          <div>
            <Button asChild variant="outline">
              <Link href={`/courses/${certificate.course_slug}`}>{t("seeCourse")}</Link>
            </Button>
          </div>
        ) : null}
      </section>
    </div>
  );
}
