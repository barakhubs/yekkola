"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { success } from "@yekkola/api-client";
import { useProvincesIndex } from "@yekkola/api-client/endpoints/province";
import {
  getProfessorProfileShowQueryKey,
  professorProfileAvatar,
  professorProfileUpdate,
} from "@yekkola/api-client/endpoints/studio-profile";
import type { ProfessorProfileResource } from "@yekkola/api-client/model";
import { PageHeader } from "@yekkola/ui/blocks/page-header";
import { Button } from "@yekkola/ui/components/button";
import { Input } from "@yekkola/ui/components/input";
import { Label } from "@yekkola/ui/components/label";
import { Textarea } from "@yekkola/ui/components/textarea";
import { Plus, UserRound, X } from "lucide-react";
import Image from "next/image";
import { useTranslations } from "next-intl";
import { type FormEvent, type ReactNode, useState } from "react";

import { Link } from "@/i18n/navigation";
import { setResource } from "@/lib/query-cache";

import { fieldErrorsOf, useErrorText } from "../hooks";
import { selectClass } from "../styles";
import { useStudioProfile } from "./professor-shell";

/** Public profile (PRD-02 FR-07): photo, name, headline, bio, subjects, links, province; slug editable once. */
export function ProfileForm() {
  const profile = useStudioProfile();

  return profile.data ? <Editor key={profile.data.id} profile={profile.data} /> : null;
}

function Editor({ profile }: { profile: ProfessorProfileResource }) {
  const t = useTranslations("web.onboarding");
  const errorText = useErrorText();
  const queryClient = useQueryClient();
  const provinces = useProvincesIndex({ query: { select: (r) => success(r).data, staleTime: 60 * 60_000 } });
  const [displayName, setDisplayName] = useState(profile.display_name);
  const [slug, setSlug] = useState(profile.slug);
  const [headline, setHeadline] = useState(profile.headline ?? "");
  const [bio, setBio] = useState(profile.bio ?? "");
  const [subjects, setSubjects] = useState(profile.subjects.join(", "));
  const [links, setLinks] = useState(profile.links);
  const [provinceId, setProvinceId] = useState(profile.province?.id ?? "");
  const store = (next: ProfessorProfileResource) =>
    setResource(queryClient, getProfessorProfileShowQueryKey(), next);

  const save = useMutation({
    mutationFn: async () =>
      success(
        await professorProfileUpdate({
          display_name: displayName.trim(),
          ...(profile.can_change_slug && slug !== profile.slug ? { slug: slug.trim() } : {}),
          headline: headline.trim() || null,
          bio: bio.trim() || null,
          subjects: subjects
            .split(",")
            .map((s) => s.trim())
            .filter(Boolean),
          links: links.filter((l) => l.label.trim() && l.url.trim()),
          province_id: provinceId || null,
        }),
      ).data,
    onSuccess: store,
  });
  const avatar = useMutation({
    mutationFn: async (file: File) => success(await professorProfileAvatar({ avatar: file })).data,
    onSuccess: store,
  });
  const errors = fieldErrorsOf(save.error);

  return (
    <div className="mx-auto grid max-w-3xl gap-6 px-4 py-8">
      <PageHeader
        title={t("profileTitle")}
        description={t("profileIntro")}
        actions={
          <Button asChild variant="outline" size="sm">
            <Link href={`/professors/${profile.slug}`}>{t("viewPublic")}</Link>
          </Button>
        }
      />

      <section className="flex flex-wrap items-center gap-4 rounded-xl border bg-background p-4">
        <div className="relative size-20 overflow-hidden rounded-full bg-muted">
          {profile.avatar_url ? (
            <Image src={profile.avatar_url} alt="" fill sizes="80px" className="object-cover" />
          ) : (
            <UserRound aria-hidden className="m-auto mt-5 size-10 text-muted-foreground" />
          )}
        </div>
        <div className="grid gap-1">
          <Label htmlFor="avatar">{t("fields.avatar")}</Label>
          <Input
            id="avatar"
            type="file"
            accept="image/jpeg,image/png,image/webp"
            disabled={avatar.isPending}
            onChange={(e) => {
              const file = e.target.files?.[0];
              e.target.value = "";
              if (file) avatar.mutate(file);
            }}
          />
          {avatar.isError ? (
            <p role="alert" className="text-sm text-destructive">
              {fieldErrorsOf(avatar.error).avatar ?? errorText(avatar.error)}
            </p>
          ) : null}
        </div>
      </section>

      <form
        noValidate
        className="grid gap-4 rounded-xl border bg-background p-4 sm:p-6"
        onSubmit={(e: FormEvent) => {
          e.preventDefault();
          if (!save.isPending) save.mutate();
        }}
      >
        <Field id="display-name" label={t("fields.displayName")} error={errors.display_name}>
          <Input id="display-name" value={displayName} onChange={(e) => setDisplayName(e.target.value)} />
        </Field>
        <Field
          id="slug"
          label={t("fields.slug")}
          hint={profile.can_change_slug ? t("slugOnce") : t("slugLocked")}
          error={errors.slug}
        >
          <Input
            id="slug"
            value={slug}
            disabled={!profile.can_change_slug}
            onChange={(e) => setSlug(e.target.value)}
          />
        </Field>
        <Field id="headline" label={t("fields.headline")} hint={t("headlineHint")} error={errors.headline}>
          <Input id="headline" value={headline} onChange={(e) => setHeadline(e.target.value)} />
        </Field>
        <Field id="bio" label={t("fields.bio")} error={errors.bio}>
          <Textarea id="bio" rows={6} value={bio} onChange={(e) => setBio(e.target.value)} />
        </Field>
        <Field id="subjects" label={t("fields.subjects")} hint={t("subjectsHint")} error={errors.subjects}>
          <Input id="subjects" value={subjects} onChange={(e) => setSubjects(e.target.value)} />
        </Field>
        <Field id="province" label={t("fields.province")} error={errors.province_id}>
          <select
            id="province"
            className={selectClass}
            value={provinceId}
            onChange={(e) => setProvinceId(e.target.value)}
          >
            <option value="">{t("provinceNone")}</option>
            {(provinces.data ?? []).map((p) => (
              <option key={p.id} value={p.id}>
                {p.name}
              </option>
            ))}
          </select>
        </Field>

        <fieldset className="grid gap-2">
          <legend className="mb-1 text-sm font-semibold">{t("fields.links")}</legend>
          {links.map((link, i) => (
            <div key={i} className="grid gap-2 sm:grid-cols-[10rem_1fr_auto]">
              <Input
                aria-label={t("linkLabel", { number: i + 1 })}
                value={link.label}
                onChange={(e) =>
                  setLinks(links.map((l, j) => (j === i ? { ...l, label: e.target.value } : l)))
                }
              />
              <Input
                aria-label={t("linkUrl", { number: i + 1 })}
                type="url"
                placeholder="https://"
                value={link.url}
                onChange={(e) => setLinks(links.map((l, j) => (j === i ? { ...l, url: e.target.value } : l)))}
              />
              <Button
                type="button"
                variant="ghost"
                size="icon"
                aria-label={t("removeLink", { number: i + 1 })}
                onClick={() => setLinks(links.filter((_, j) => j !== i))}
              >
                <X aria-hidden />
              </Button>
            </div>
          ))}
          {Object.entries(errors)
            .filter(([k]) => k.startsWith("links"))
            .map(([k, m]) => (
              <p key={k} className="text-sm text-destructive">
                {m}
              </p>
            ))}
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="w-fit"
            onClick={() => setLinks([...links, { label: "", url: "" }])}
          >
            <Plus aria-hidden />
            {t("addLink")}
          </Button>
        </fieldset>

        <div className="flex flex-wrap items-center gap-3">
          <Button type="submit" disabled={save.isPending} aria-busy={save.isPending}>
            {t("save")}
          </Button>
          {save.isSuccess ? <p className="text-sm text-muted-foreground">{t("saved")}</p> : null}
          {save.isError && Object.keys(errors).length === 0 ? (
            <p role="alert" className="text-sm text-destructive">
              {errorText(save.error)}
            </p>
          ) : null}
        </div>
      </form>
    </div>
  );
}

function Field({
  id,
  label,
  hint,
  error,
  children,
}: {
  id: string;
  label: string;
  hint?: string;
  error?: string;
  children: ReactNode;
}) {
  return (
    <div className="grid gap-2">
      <Label htmlFor={id}>{label}</Label>
      {children}
      {hint ? <p className="text-xs text-muted-foreground">{hint}</p> : null}
      {error ? <p className="text-sm text-destructive">{error}</p> : null}
    </div>
  );
}
