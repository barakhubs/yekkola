import type { PublicProfessorResource } from "@yekkola/api-client/model";
import { BadgeCheck, MapPin, Star, UserRound } from "lucide-react";
import Image from "next/image";
import { getTranslations } from "next-intl/server";

import { Link } from "@/i18n/navigation";

interface ProfessorSummaryProps {
  professor: PublicProfessorResource;
  /** Course page: compact card linking to the profile. Profile page: heading level 1. */
  variant: "card" | "header";
}

/** Photo, name, verified badge, headline, province, rating and students (PRD-04 FR-07, PRD-02 FR-12). */
export async function ProfessorSummary({ professor, variant }: ProfessorSummaryProps) {
  const t = await getTranslations("web.catalog");
  const Heading = variant === "header" ? "h1" : "h3";
  const size = variant === "header" ? 96 : 64;

  return (
    <div className="flex items-start gap-4">
      <div
        className="relative shrink-0 overflow-hidden rounded-full bg-indigo-100 dark:bg-indigo-950"
        style={{ width: size, height: size }}
      >
        {professor.avatar_url ? (
          <Image src={professor.avatar_url} alt="" fill sizes={`${size}px`} className="object-cover" />
        ) : (
          <UserRound aria-hidden className="absolute inset-0 m-auto size-1/2 text-indigo-400" />
        )}
      </div>
      <div className="grid gap-1">
        <Heading
          className={variant === "header" ? "text-2xl font-extrabold sm:text-3xl" : "text-lg font-bold"}
        >
          {variant === "card" ? (
            <Link href={`/professors/${professor.slug}`} className="hover:underline">
              {professor.display_name}
            </Link>
          ) : (
            professor.display_name
          )}
          {professor.is_verified ? (
            <BadgeCheck
              aria-label={t("verified")}
              className="ml-1 inline size-5 align-text-bottom text-primary"
            />
          ) : null}
        </Heading>
        {professor.headline ? <p className="text-muted-foreground">{professor.headline}</p> : null}
        <p className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
          {professor.rating !== null ? (
            <span className="inline-flex items-center gap-1">
              <Star aria-hidden className="size-4 fill-amber-300 stroke-amber-500" />
              {t("rating", { rating: professor.rating, count: professor.rating_count })}
            </span>
          ) : null}
          <span>{t("students", { count: professor.student_count })}</span>
          {professor.province ? (
            <span className="inline-flex items-center gap-1">
              <MapPin aria-hidden className="size-4" />
              {professor.province.name}
            </span>
          ) : null}
        </p>
      </div>
    </div>
  );
}
