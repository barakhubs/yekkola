"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { ApiError, success } from "@yekkola/api-client";
import {
  getProfessorApplicationShowQueryKey,
  professorApplicationCredentialsDestroy,
  professorApplicationCredentialsStore,
  professorApplicationSave,
  professorApplicationSubmit,
} from "@yekkola/api-client/endpoints/application";
import { useProvincesIndex } from "@yekkola/api-client/endpoints/province";
import type { ProfessorApplicationResource, TeachingLevel } from "@yekkola/api-client/model";
import { formatBytes, type Locale } from "@yekkola/i18n";
import { Button } from "@yekkola/ui/components/button";
import { Input } from "@yekkola/ui/components/input";
import { Label } from "@yekkola/ui/components/label";
import { Textarea } from "@yekkola/ui/components/textarea";
import { cn } from "@yekkola/ui/lib/utils";
import { FileText, Info, Loader2, Plus, X } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { type FormEvent, type ReactNode, useEffect, useId, useRef, useState } from "react";

const STEPS = ["about", "teaching", "credentials", "motivation"] as const;
type Step = (typeof STEPS)[number];
const LEVELS: TeachingLevel[] = ["secondary", "exetat", "university", "professional"];
/** Where each field lives, to send the applicant to the right step. */
const FIELD_STEP: Record<string, Step> = {
  full_name: "about",
  province_id: "about",
  subjects: "teaching",
  levels: "teaching",
  years_experience: "teaching",
  institutions: "teaching",
  credentials: "credentials",
  sample_url: "credentials",
  motivation: "motivation",
};

const selectClass =
  "h-10 w-full rounded-md border border-input bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none";

interface Fields {
  full_name: string;
  province_id: string;
  subjects: string[];
  levels: TeachingLevel[];
  years_experience: string;
  institutions: string;
  credentials: string;
  sample_url: string;
  motivation: string;
}

function fieldsOf(a: ProfessorApplicationResource | null): Fields {
  return {
    full_name: a?.full_name ?? "",
    province_id: a?.province?.id ?? "",
    subjects: a?.subjects ?? [],
    levels: (a?.levels ?? []) as TeachingLevel[],
    years_experience: a?.years_experience == null ? "" : String(a.years_experience),
    institutions: a?.institutions ?? "",
    credentials: a?.credentials ?? "",
    sample_url: a?.sample_url ?? "",
    motivation: a?.motivation ?? "",
  };
}

/**
 * The application (PRD-02 FR-01, FR-02): four short steps that work on a phone. The draft is saved as the
 * applicant goes (a moment after they stop typing, and on every step change), so nothing is lost; submitting
 * accepts the professor agreement and locks it. What still blocks submission comes from the API.
 */
export function ApplicationForm({ application }: { application: ProfessorApplicationResource | null }) {
  const t = useTranslations("web.apply");
  const errors = useTranslations("errors");
  const queryClient = useQueryClient();
  const [step, setStep] = useState<Step>("about");
  const [fields, setFields] = useState<Fields>(() => fieldsOf(application));
  const [dirty, setDirty] = useState(false);
  const [accept, setAccept] = useState(false);
  const heading = useRef<HTMLHeadingElement>(null);
  const current = application;

  const store = (next: ProfessorApplicationResource) =>
    queryClient.setQueryData(getProfessorApplicationShowQueryKey(), next);

  const save = useMutation({
    mutationFn: async () =>
      success(
        await professorApplicationSave({
          full_name: fields.full_name.trim() || null,
          province_id: fields.province_id || null,
          subjects: fields.subjects,
          levels: fields.levels,
          years_experience: fields.years_experience === "" ? null : Number(fields.years_experience),
          institutions: fields.institutions.trim() || null,
          credentials: fields.credentials.trim() || null,
          sample_url: fields.sample_url.trim() || null,
          motivation: fields.motivation.trim() || null,
        }),
      ).data,
    onSuccess: (saved) => {
      setDirty(false);
      store(saved);
    },
  });

  // Autosave a moment after the last change (FR-02).
  useEffect(() => {
    if (!dirty || save.isPending) return;
    const timer = window.setTimeout(() => save.mutate(), 1500);
    return () => window.clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps -- re-arm on every edit
  }, [fields, dirty]);

  const submit = useMutation({
    mutationFn: async () => {
      if (dirty || !current) await save.mutateAsync();
      return success(await professorApplicationSubmit({ accept_terms: true })).data;
    },
    onSuccess: store,
  });

  const fieldErrors = save.error instanceof ApiError ? save.error.fieldErrors() : {};
  const update = <K extends keyof Fields>(key: K, value: Fields[K]) => {
    setFields((previous) => ({ ...previous, [key]: value }));
    setDirty(true);
  };
  const go = (next: Step) => {
    if (dirty) save.mutate();
    setStep(next);
    requestAnimationFrame(() => heading.current?.focus());
  };
  const index = STEPS.indexOf(step);
  // From the API (saved draft); before the first save there is nothing to report yet.
  const missing = current?.missing ?? [];

  return (
    <div className="grid gap-5">
      {current?.status === "needs_info" && current.info_request_note ? (
        <div className="flex items-start gap-3 rounded-lg border border-brand-accent bg-brand-accent/15 p-4">
          <Info aria-hidden className="mt-0.5 size-5 shrink-0" />
          <div className="grid gap-1">
            <p className="font-semibold">{t("needsInfoTitle")}</p>
            <p className="text-sm whitespace-pre-line">{current.info_request_note}</p>
          </div>
        </div>
      ) : null}

      <ol className="grid grid-cols-4 gap-2" aria-label={t("progressLabel")}>
        {STEPS.map((s, i) => (
          <li key={s}>
            <button
              type="button"
              onClick={() => go(s)}
              aria-current={s === step ? "step" : undefined}
              className={cn(
                "grid w-full gap-1 rounded-md border-t-4 pt-2 text-start text-xs font-semibold focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:text-sm",
                i <= index ? "border-primary text-foreground" : "border-muted text-muted-foreground",
              )}
            >
              <span className="text-muted-foreground">
                {t("stepOf", { step: i + 1, total: STEPS.length })}
              </span>
              {t(`steps.${s}`)}
            </button>
          </li>
        ))}
      </ol>

      <form
        className="grid min-w-0 gap-5 rounded-xl border bg-background p-4 sm:p-6"
        onSubmit={(e: FormEvent) => {
          e.preventDefault();
          if (index < STEPS.length - 1) go(STEPS[index + 1] as Step);
          else if (accept && !submit.isPending) submit.mutate();
        }}
      >
        <h2 ref={heading} tabIndex={-1} className="text-xl font-bold focus:outline-none">
          {t(`steps.${step}`)}
        </h2>

        {step === "about" ? (
          <AboutStep fields={fields} errors={fieldErrors} update={update} />
        ) : step === "teaching" ? (
          <TeachingStep fields={fields} errors={fieldErrors} update={update} />
        ) : step === "credentials" ? (
          <CredentialsStep
            fields={fields}
            errors={fieldErrors}
            update={update}
            application={current}
            ensureDraft={async () => (current ? current : await save.mutateAsync())}
            onChanged={store}
          />
        ) : (
          <MotivationStep fields={fields} errors={fieldErrors} update={update} />
        )}

        {step === "motivation" ? (
          <div className="grid gap-3 border-t pt-4">
            {missing.length > 0 ? (
              <div className="grid gap-1 text-sm">
                <p className="font-semibold">{t("missingTitle")}</p>
                <ul className="flex flex-wrap gap-2">
                  {missing.map((field) => (
                    <li key={field}>
                      <button
                        type="button"
                        className="rounded-full border px-3 py-1 hover:bg-muted"
                        onClick={() => go(FIELD_STEP[field] ?? "about")}
                      >
                        {t(`fieldNames.${field as "full_name"}`)}
                      </button>
                    </li>
                  ))}
                </ul>
              </div>
            ) : null}
            <label className="flex items-start gap-2 text-sm">
              <input
                type="checkbox"
                className="mt-0.5 size-4 accent-primary"
                checked={accept}
                onChange={(e) => setAccept(e.target.checked)}
              />
              <span>{t("acceptTerms")}</span>
            </label>
          </div>
        ) : null}

        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex gap-2">
            {index > 0 ? (
              <Button type="button" variant="outline" onClick={() => go(STEPS[index - 1] as Step)}>
                {t("back")}
              </Button>
            ) : null}
            {index < STEPS.length - 1 ? (
              <Button type="submit">{t("next")}</Button>
            ) : (
              <Button
                type="submit"
                disabled={!accept || missing.length > 0 || submit.isPending}
                aria-busy={submit.isPending}
              >
                {current?.status === "needs_info" ? t("resubmit") : t("submit")}
              </Button>
            )}
          </div>
          <p role="status" className="text-xs text-muted-foreground">
            {save.isPending ? t("saving") : dirty ? "" : current ? t("savedDraft") : ""}
          </p>
        </div>

        {save.isError && Object.keys(fieldErrors).length === 0 ? (
          <p role="alert" className="text-sm text-destructive">
            {save.error instanceof ApiError ? (save.error.title ?? errors("generic")) : errors("network")}
          </p>
        ) : null}
        {Object.keys(fieldErrors).length > 0 ? (
          <p role="alert" className="text-sm text-destructive">
            {t("fixErrors")}
          </p>
        ) : null}
        {submit.isError ? (
          <p role="alert" className="text-sm text-destructive">
            {submit.error instanceof ApiError ? (submit.error.title ?? errors("generic")) : errors("network")}
          </p>
        ) : null}
      </form>
    </div>
  );
}

type StepProps = {
  fields: Fields;
  errors: Record<string, string>;
  update: <K extends keyof Fields>(key: K, value: Fields[K]) => void;
};

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
      {hint ? (
        <p id={`${id}-hint`} className="text-xs text-muted-foreground">
          {hint}
        </p>
      ) : null}
      {error ? (
        <p id={`${id}-error`} className="text-sm text-destructive">
          {error}
        </p>
      ) : null}
    </div>
  );
}

function AboutStep({ fields, errors, update }: StepProps) {
  const t = useTranslations("web.apply");
  const provinces = useProvincesIndex({ query: { select: (r) => success(r).data, staleTime: 60 * 60_000 } });

  return (
    <>
      <Field id="full-name" label={t("fields.fullName")} hint={t("hints.fullName")} error={errors.full_name}>
        <Input
          id="full-name"
          autoComplete="name"
          maxLength={120}
          value={fields.full_name}
          aria-invalid={errors.full_name ? true : undefined}
          aria-describedby="full-name-hint full-name-error"
          onChange={(e) => update("full_name", e.target.value)}
        />
      </Field>
      <Field id="province" label={t("fields.province")} hint={t("hints.province")} error={errors.province_id}>
        <select
          id="province"
          className={selectClass}
          value={fields.province_id}
          aria-describedby="province-hint"
          onChange={(e) => update("province_id", e.target.value)}
        >
          <option value="">{t("provinceNone")}</option>
          {(provinces.data ?? []).map((province) => (
            <option key={province.id} value={province.id}>
              {province.name}
            </option>
          ))}
        </select>
      </Field>
    </>
  );
}

function TeachingStep({ fields, errors, update }: StepProps) {
  const t = useTranslations("web.apply");
  const [subject, setSubject] = useState("");
  const addSubject = () => {
    const value = subject.trim();
    if (value.length >= 2 && !fields.subjects.includes(value) && fields.subjects.length < 10) {
      update("subjects", [...fields.subjects, value]);
    }
    setSubject("");
  };
  const subjectErrors = Object.entries(errors).filter(
    ([key]) => key === "subjects" || key.startsWith("subjects."),
  );

  return (
    <>
      <div className="grid gap-2">
        <Label htmlFor="subject">{t("fields.subjects")}</Label>
        <div className="flex gap-2">
          <Input
            id="subject"
            value={subject}
            maxLength={60}
            aria-describedby="subject-hint"
            onChange={(e) => setSubject(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === "Enter") {
                e.preventDefault();
                addSubject();
              }
            }}
          />
          <Button
            type="button"
            variant="outline"
            onClick={addSubject}
            disabled={fields.subjects.length >= 10}
          >
            <Plus aria-hidden />
            {t("addSubject")}
          </Button>
        </div>
        <p id="subject-hint" className="text-xs text-muted-foreground">
          {t("hints.subjects")}
        </p>
        {fields.subjects.length > 0 ? (
          <ul className="flex flex-wrap gap-2" aria-label={t("subjectsAdded")}>
            {fields.subjects.map((s) => (
              <li key={s} className="flex items-center gap-1 rounded-full bg-accent py-1 ps-3 pe-1 text-sm">
                {s}
                <button
                  type="button"
                  className="rounded-full p-1 hover:bg-muted"
                  aria-label={t("removeSubject", { subject: s })}
                  onClick={() =>
                    update(
                      "subjects",
                      fields.subjects.filter((x) => x !== s),
                    )
                  }
                >
                  <X aria-hidden className="size-3.5" />
                </button>
              </li>
            ))}
          </ul>
        ) : null}
        {subjectErrors.map(([key, message]) => (
          <p key={key} className="text-sm text-destructive">
            {message}
          </p>
        ))}
      </div>

      <fieldset className="grid gap-2">
        <legend className="mb-1 text-sm font-semibold">{t("fields.levels")}</legend>
        <div className="grid gap-2 sm:grid-cols-2">
          {LEVELS.map((level) => (
            <label key={level} className="flex items-center gap-2 rounded-md border p-3 text-sm">
              <input
                type="checkbox"
                className="size-4 accent-primary"
                checked={fields.levels.includes(level)}
                onChange={(e) =>
                  update(
                    "levels",
                    e.target.checked ? [...fields.levels, level] : fields.levels.filter((l) => l !== level),
                  )
                }
              />
              {t(`levels.${level}`)}
            </label>
          ))}
        </div>
        {errors.levels ? <p className="text-sm text-destructive">{errors.levels}</p> : null}
      </fieldset>

      <Field id="years" label={t("fields.years")} error={errors.years_experience}>
        <Input
          id="years"
          type="number"
          inputMode="numeric"
          min={0}
          max={60}
          className="max-w-32"
          value={fields.years_experience}
          aria-invalid={errors.years_experience ? true : undefined}
          aria-describedby="years-error"
          onChange={(e) => update("years_experience", e.target.value)}
        />
      </Field>

      <Field
        id="institutions"
        label={t("fields.institutions")}
        hint={t("hints.institutions")}
        error={errors.institutions}
      >
        <Textarea
          id="institutions"
          rows={3}
          maxLength={2000}
          value={fields.institutions}
          aria-describedby="institutions-hint institutions-error"
          onChange={(e) => update("institutions", e.target.value)}
        />
      </Field>
    </>
  );
}

function CredentialsStep({
  fields,
  errors,
  update,
  application,
  ensureDraft,
  onChanged,
}: StepProps & {
  application: ProfessorApplicationResource | null;
  ensureDraft: () => Promise<ProfessorApplicationResource>;
  onChanged: (application: ProfessorApplicationResource) => void;
}) {
  const t = useTranslations("web.apply");
  const errorsT = useTranslations("errors");
  const locale = useLocale() as Locale;
  const queryClient = useQueryClient();
  const fileInput = useId();
  const files = application?.credential_files ?? [];

  const upload = useMutation({
    mutationFn: async (file: File) => {
      await ensureDraft();
      return success(await professorApplicationCredentialsStore({ file })).data;
    },
    onSuccess: onChanged,
  });
  const remove = useMutation({
    mutationFn: (id: string) => professorApplicationCredentialsDestroy(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: getProfessorApplicationShowQueryKey() }),
  });
  const fileError = (error: unknown) =>
    error instanceof ApiError
      ? (error.fieldErrors().file ?? error.title ?? errorsT("generic"))
      : errorsT("network");

  return (
    <>
      <Field
        id="credentials"
        label={t("fields.credentials")}
        hint={t("hints.credentials")}
        error={errors.credentials}
      >
        <Textarea
          id="credentials"
          rows={4}
          maxLength={4000}
          value={fields.credentials}
          aria-describedby="credentials-hint credentials-error"
          onChange={(e) => update("credentials", e.target.value)}
        />
      </Field>

      <div className="grid min-w-0 gap-2">
        <Label htmlFor={fileInput}>{t("fields.files")}</Label>
        {files.length > 0 ? (
          <ul className="grid min-w-0 gap-2">
            {files.map((file) => (
              <li
                key={file.id ?? file.name}
                className="flex min-w-0 items-center justify-between gap-2 rounded-md border p-2 text-sm"
              >
                <span className="flex min-w-0 items-center gap-2">
                  <FileText aria-hidden className="size-4 shrink-0" />
                  <span className="truncate">{file.name}</span>
                  <span className="shrink-0 whitespace-nowrap text-muted-foreground">
                    · {formatBytes(file.size, locale)}
                  </span>
                </span>
                {file.id ? (
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    disabled={remove.isPending}
                    aria-label={t("removeFile", { name: file.name })}
                    onClick={() => file.id && remove.mutate(file.id)}
                  >
                    <X aria-hidden />
                  </Button>
                ) : null}
              </li>
            ))}
          </ul>
        ) : null}
        <Input
          id={fileInput}
          type="file"
          accept="application/pdf,image/jpeg,image/png"
          disabled={upload.isPending}
          aria-describedby={`${fileInput}-hint`}
          onChange={(e) => {
            const file = e.target.files?.[0];
            e.target.value = "";
            if (file) upload.mutate(file);
          }}
        />
        <p id={`${fileInput}-hint`} className="text-xs text-muted-foreground">
          {t("hints.files")}
        </p>
        {upload.isPending ? (
          <p role="status" className="flex items-center gap-2 text-sm">
            <Loader2 aria-hidden className="size-4 animate-spin" />
            {t("uploading")}
          </p>
        ) : null}
        {upload.isError || remove.isError ? (
          <p role="alert" className="text-sm text-destructive">
            {fileError(upload.error ?? remove.error)}
          </p>
        ) : null}
      </div>

      <Field id="sample" label={t("fields.sample")} hint={t("hints.sample")} error={errors.sample_url}>
        <Input
          id="sample"
          type="url"
          inputMode="url"
          placeholder="https://"
          maxLength={500}
          value={fields.sample_url}
          aria-invalid={errors.sample_url ? true : undefined}
          aria-describedby="sample-hint sample-error"
          onChange={(e) => update("sample_url", e.target.value)}
        />
      </Field>
    </>
  );
}

function MotivationStep({ fields, errors, update }: StepProps) {
  const t = useTranslations("web.apply");

  return (
    <Field
      id="motivation"
      label={t("fields.motivation")}
      hint={t("hints.motivation")}
      error={errors.motivation}
    >
      <Textarea
        id="motivation"
        rows={6}
        maxLength={4000}
        value={fields.motivation}
        aria-invalid={errors.motivation ? true : undefined}
        aria-describedby="motivation-hint motivation-error"
        onChange={(e) => update("motivation", e.target.value)}
      />
    </Field>
  );
}
