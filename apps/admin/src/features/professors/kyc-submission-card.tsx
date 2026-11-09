"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import {
  adminKycSubmissionsReject,
  adminKycSubmissionsVerify,
  getAdminKycSubmissionsIndexQueryKey,
} from "@yekkola/api-client/endpoints/kyc-submission";
import {
  getAdminProfessorsIndexQueryKey,
  getAdminProfessorsShowQueryKey,
} from "@yekkola/api-client/endpoints/professor";
import type { KycSubmissionResource } from "@yekkola/api-client/model";
import { formatDateTime, type Locale } from "@yekkola/i18n";
import { ConfirmDialog } from "@yekkola/ui/blocks/confirm-dialog";
import { StatusBadge } from "@yekkola/ui/blocks/status-badge";
import { Button } from "@yekkola/ui/components/button";
import { ExternalLink } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";

import { useSession } from "@/features/auth/session";
import { useErrorText } from "@/features/common/errors";
import { Link } from "@/i18n/navigation";

import { KYC_TONES, kycStateOf } from "./status";

const FILES = ["front", "back", "selfie"] as const;

/**
 * One identity check (PRD-02 FR-08, PRD-09 FR-06): document type, the images (each opens in a new tab, streamed by
 * the API and recorded in the audit log), and verify / reject for staff with `kyc.review` while it is pending.
 */
export function KycSubmissionCard({
  submission,
  showProfessor = false,
}: {
  submission: KycSubmissionResource;
  showProfessor?: boolean;
}) {
  const t = useTranslations("admin.kyc");
  const common = useTranslations("common");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const session = useSession();
  const queryClient = useQueryClient();
  const state = kycStateOf(submission.status);
  const professor = submission.professor;
  const files = (submission.files ?? {}) as Partial<Record<(typeof FILES)[number], string>>;

  const decide = useMutation({
    mutationFn: async ({ verify, note }: { verify: boolean; note: string }) => {
      if (verify) await adminKycSubmissionsVerify(submission.id);
      else await adminKycSubmissionsReject(submission.id, { note });
    },
    onSettled: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: getAdminKycSubmissionsIndexQueryKey() }),
        queryClient.invalidateQueries({ queryKey: getAdminProfessorsIndexQueryKey() }),
        ...(professor
          ? [queryClient.invalidateQueries({ queryKey: getAdminProfessorsShowQueryKey(professor.id) })]
          : []),
      ]);
    },
  });
  const errorFor = (verify: boolean) =>
    decide.isError && decide.variables?.verify === verify ? errorText(decide.error) : undefined;

  return (
    <article className="grid gap-3 rounded-xl border p-4">
      <header className="flex flex-wrap items-start justify-between gap-2">
        <div className="grid gap-1">
          {showProfessor && professor ? (
            session.can("professors.manage") ? (
              <Link
                href={`/professors/${professor.id}`}
                className="font-semibold text-primary hover:underline"
              >
                {professor.display_name}
              </Link>
            ) : (
              <span className="font-semibold">{professor.display_name}</span>
            )
          ) : null}
          <span className="text-sm">
            {t(`documents.${submission.document_type}` as never)} ·{" "}
            {t("submitted", { date: formatDateTime(submission.submitted_at, locale) })}
          </span>
        </div>
        <StatusBadge status={state} tones={KYC_TONES} label={t(`status.${state}`)} />
      </header>

      <ul className="flex flex-wrap gap-2" aria-label={t("files")}>
        {FILES.map((kind) =>
          files[kind] ? (
            <li key={kind}>
              <Button asChild size="sm" variant="outline">
                <a href={files[kind]} target="_blank" rel="noopener noreferrer">
                  {t(`fileKinds.${kind}`)}
                  <ExternalLink aria-hidden className="size-3.5" />
                </a>
              </Button>
            </li>
          ) : null,
        )}
      </ul>
      <p className="text-xs text-muted-foreground">{t("audited")}</p>

      {submission.rejection_reason ? (
        <p className="text-sm">
          <span className="font-semibold">{t("rejectionReason")}</span> {submission.rejection_reason}
        </p>
      ) : null}
      {submission.reviewed_at ? (
        <p className="text-xs text-muted-foreground">
          {t("reviewed", { date: formatDateTime(submission.reviewed_at, locale) })}
        </p>
      ) : null}

      {state === "pending" && session.can("kyc.review") ? (
        <div className="flex flex-wrap gap-2">
          <ConfirmDialog
            trigger={<Button>{t("verify")}</Button>}
            title={t("verifyTitle")}
            description={t("verifyHint")}
            confirmLabel={t("verify")}
            cancelLabel={common("cancel")}
            onConfirm={async () => {
              await decide.mutateAsync({ verify: true, note: "" });
            }}
            error={errorFor(true)}
            onOpenChange={(open) => open && decide.reset()}
          />
          <ConfirmDialog
            trigger={<Button variant="outline">{t("reject")}</Button>}
            title={t("rejectTitle")}
            description={t("rejectHint")}
            reason={{ label: t("rejectReason"), minLength: 3 }}
            confirmLabel={t("reject")}
            cancelLabel={common("cancel")}
            destructive
            onConfirm={async (note) => {
              await decide.mutateAsync({ verify: false, note });
            }}
            error={errorFor(false)}
            onOpenChange={(open) => open && decide.reset()}
          />
        </div>
      ) : null}
    </article>
  );
}
