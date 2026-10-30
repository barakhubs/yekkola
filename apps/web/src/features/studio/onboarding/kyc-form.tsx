"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ApiError, success } from "@yekkola/api-client";
import {
  getProfessorKycShowQueryKey,
  professorKycShow,
  professorKycStore,
} from "@yekkola/api-client/endpoints/kyc";
import { getProfessorProfileShowQueryKey } from "@yekkola/api-client/endpoints/studio-profile";
import type { KycDocumentType, KycSubmissionResource } from "@yekkola/api-client/model";
import { formatDateTime, type Locale } from "@yekkola/i18n";
import { PageHeader } from "@yekkola/ui/blocks/page-header";
import { Button } from "@yekkola/ui/components/button";
import { Input } from "@yekkola/ui/components/input";
import { Label } from "@yekkola/ui/components/label";
import { Skeleton } from "@yekkola/ui/components/skeleton";
import { CheckCircle2, Clock, ShieldCheck, XCircle } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { type FormEvent, useState } from "react";

import { setResource } from "@/lib/query-cache";

import { fieldErrorsOf, useErrorText } from "../hooks";

const TYPES: KycDocumentType[] = ["national_id", "voter_card", "passport"];

/**
 * Identity check (PRD-02 FR-08, BR-01): ID front (+ back) and a selfie, stored privately and reviewed by staff.
 * Needed before any payout. Shows the latest check's status; a rejected one can be sent again.
 */
export function KycForm() {
  const t = useTranslations("web.onboarding");
  const locale = useLocale() as Locale;
  const latest = useQuery({
    queryKey: getProfessorKycShowQueryKey(),
    queryFn: async (): Promise<KycSubmissionResource | null> => {
      try {
        return success(await professorKycShow()).data;
      } catch (error) {
        if (error instanceof ApiError && error.code === "kyc.not_found") return null;
        throw error;
      }
    },
  });
  const k = latest.data;

  return (
    <div className="mx-auto grid max-w-3xl gap-6 px-4 py-8">
      <PageHeader title={t("kycTitle")} description={t("kycIntro")} />
      {latest.isPending ? (
        <Skeleton className="h-40 w-full" />
      ) : k?.status === "verified" ? (
        <Status icon={<CheckCircle2 aria-hidden className="size-6 text-success" />} text={t("kycVerified")} />
      ) : k?.status === "pending" ? (
        <Status
          icon={<Clock aria-hidden className="size-6 text-primary" />}
          text={t("kycPending", { date: formatDateTime(k.submitted_at, locale) })}
        />
      ) : (
        <>
          {k?.status === "rejected" ? (
            <Status
              icon={<XCircle aria-hidden className="size-6 text-destructive" />}
              text={t("kycRejected", { reason: k.rejection_reason ?? "" })}
            />
          ) : null}
          <SubmitForm />
        </>
      )}
      <p className="flex items-start gap-2 text-xs text-muted-foreground">
        <ShieldCheck aria-hidden className="size-4 shrink-0" />
        {t("kycPrivacy")}
      </p>
    </div>
  );
}

function Status({ icon, text }: { icon: React.ReactNode; text: string }) {
  return (
    <p role="status" className="flex items-start gap-3 rounded-xl border bg-background p-4">
      {icon}
      <span>{text}</span>
    </p>
  );
}

function SubmitForm() {
  const t = useTranslations("web.onboarding");
  const errorText = useErrorText();
  const queryClient = useQueryClient();
  const [type, setType] = useState<KycDocumentType>("national_id");
  const [front, setFront] = useState<File | null>(null);
  const [back, setBack] = useState<File | null>(null);
  const [selfie, setSelfie] = useState<File | null>(null);

  const submit = useMutation({
    mutationFn: async () =>
      success(
        await professorKycStore({
          document_type: type,
          front: front as File,
          back: type === "passport" ? null : back,
          selfie: selfie as File,
        }),
      ).data,
    onSuccess: async (saved) => {
      setResource(queryClient, getProfessorKycShowQueryKey(), saved);
      await queryClient.invalidateQueries({ queryKey: getProfessorProfileShowQueryKey() });
    },
  });
  const errors = fieldErrorsOf(submit.error);
  const file = (id: string, label: string, set: (f: File | null) => void, accept: string, error?: string) => (
    <div className="grid gap-2">
      <Label htmlFor={id}>{label}</Label>
      <Input id={id} type="file" accept={accept} onChange={(e) => set(e.target.files?.[0] ?? null)} />
      {error ? <p className="text-sm text-destructive">{error}</p> : null}
    </div>
  );

  return (
    <form
      noValidate
      className="grid gap-4 rounded-xl border bg-background p-4 sm:p-6"
      onSubmit={(e: FormEvent) => {
        e.preventDefault();
        if (front && selfie && !submit.isPending) submit.mutate();
      }}
    >
      <fieldset className="grid gap-2">
        <legend className="mb-1 text-sm font-semibold">{t("fields.documentType")}</legend>
        <div className="grid gap-2 sm:grid-cols-3">
          {TYPES.map((value) => (
            <label key={value} className="flex items-center gap-2 rounded-md border p-3 text-sm">
              <input
                type="radio"
                name="document-type"
                className="size-4 accent-primary"
                checked={type === value}
                onChange={() => setType(value)}
              />
              {t(`documentTypes.${value}`)}
            </label>
          ))}
        </div>
      </fieldset>
      {file("kyc-front", t("fields.front"), setFront, "image/jpeg,image/png,application/pdf", errors.front)}
      {type === "passport"
        ? null
        : file("kyc-back", t("fields.back"), setBack, "image/jpeg,image/png,application/pdf", errors.back)}
      {file("kyc-selfie", t("fields.selfie"), setSelfie, "image/jpeg,image/png", errors.selfie)}
      <div className="flex flex-wrap items-center gap-3">
        <Button type="submit" disabled={!front || !selfie || submit.isPending} aria-busy={submit.isPending}>
          {t("kycSubmit")}
        </Button>
        {submit.isError && Object.keys(errors).length === 0 ? (
          <p role="alert" className="text-sm text-destructive">
            {errorText(submit.error)}
          </p>
        ) : null}
      </div>
    </form>
  );
}
