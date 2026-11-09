"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { success } from "@yekkola/api-client";
import { useAdminCoursesIndex } from "@yekkola/api-client/endpoints/course";
import { useAdminKycSubmissionsIndex } from "@yekkola/api-client/endpoints/kyc-submission";
import {
  adminProfessorsReinstate,
  adminProfessorsSuspend,
  adminProfessorsUpdate,
  getAdminProfessorsIndexQueryKey,
  getAdminProfessorsShowQueryKey,
  useAdminProfessorsShow,
} from "@yekkola/api-client/endpoints/professor";
import type { AdminProfessorResource } from "@yekkola/api-client/model";
import { formatDateTime, formatMoney, type Locale } from "@yekkola/i18n";
import { ConfirmDialog } from "@yekkola/ui/blocks/confirm-dialog";
import { EmptyState } from "@yekkola/ui/blocks/empty-state";
import { StatusBadge } from "@yekkola/ui/blocks/status-badge";
import { Button } from "@yekkola/ui/components/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@yekkola/ui/components/dialog";
import { Input } from "@yekkola/ui/components/input";
import { Label } from "@yekkola/ui/components/label";
import { Skeleton } from "@yekkola/ui/components/skeleton";
import { Textarea } from "@yekkola/ui/components/textarea";
import { ArrowLeft } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { type FormEvent, type ReactNode, useState } from "react";

import { useSession } from "@/features/auth/session";
import { useErrorText } from "@/features/common/errors";
import { Link } from "@/i18n/navigation";

import { KycSubmissionCard } from "./kyc-submission-card";
import { KYC_TONES, PROFESSOR_TONES, kycStateOf, professorStateOf } from "./status";

/**
 * One professor (PRD-09 FR-06): standing, identity checks with their documents, payout method, revenue share and
 * courses. Suspend / reinstate and revenue-share changes need a reason (audit log); a new share applies to future
 * sales only.
 */
export function ProfessorDetail({ id }: { id: string }) {
  const t = useTranslations("admin.professors");
  const rails = useTranslations("admin.payouts.rails");
  const courseT = useTranslations("admin.courses");
  const common = useTranslations("common");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const session = useSession();
  const queryClient = useQueryClient();
  const professor = useAdminProfessorsShow(id, { query: { select: (r) => success(r).data } });
  const submissions = useAdminKycSubmissionsIndex(
    { "filter[professor_profile_id]": id, sort: "-created_at", per_page: 10 },
    { query: { enabled: session.can("kyc.view"), select: (r) => success(r).data } },
  );
  const courses = useAdminCoursesIndex(
    { "filter[professor_profile_id]": id, per_page: 50 },
    { query: { enabled: session.can("catalog.view"), select: (r) => success(r).data } },
  );

  const store = (data: AdminProfessorResource) => {
    queryClient.setQueryData(getAdminProfessorsShowQueryKey(id), {
      data: { data },
      status: 200,
      headers: new Headers(),
    });
    void queryClient.invalidateQueries({ queryKey: getAdminProfessorsIndexQueryKey() });
  };
  const standing = useMutation({
    mutationFn: async ({ suspend, note }: { suspend: boolean; note: string }) =>
      success(
        suspend ? await adminProfessorsSuspend(id, { note }) : await adminProfessorsReinstate(id, { note }),
      ).data,
    onSuccess: store,
  });

  if (professor.isPending) {
    return (
      <div className="mx-auto grid max-w-5xl gap-4 px-4 py-8 sm:px-6">
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }
  if (professor.isError) {
    return (
      <div className="mx-auto max-w-xl px-4 py-16">
        <EmptyState title={errorText(professor.error)} />
      </div>
    );
  }

  const p = professor.data;
  const state = professorStateOf(p.status);
  const kyc = kycStateOf(p.kyc_status);
  const suspend = state === "active";

  return (
    <div className="mx-auto grid max-w-5xl gap-6 px-4 py-8 sm:px-6">
      <Link
        href="/professors"
        className="flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
      >
        <ArrowLeft aria-hidden className="size-4" />
        {t("back")}
      </Link>

      <header className="flex flex-wrap items-start justify-between gap-4">
        <div className="grid gap-2">
          <h1 className="text-2xl font-extrabold">{p.display_name}</h1>
          {p.headline ? <p className="text-muted-foreground">{p.headline}</p> : null}
          <span className="flex flex-wrap items-center gap-2 text-sm">
            <StatusBadge status={state} tones={PROFESSOR_TONES} label={t(`status.${state}`)} />
            <StatusBadge status={kyc} tones={KYC_TONES} label={t(`kyc.${kyc}`)} />
          </span>
        </div>
        <div className="flex flex-wrap gap-2">
          <ConfirmDialog
            trigger={
              <Button variant={suspend ? "destructive" : "outline"}>
                {suspend ? t("suspend") : t("reinstate")}
              </Button>
            }
            title={
              suspend
                ? t("suspendTitle", { name: p.display_name })
                : t("reinstateTitle", { name: p.display_name })
            }
            description={suspend ? t("suspendHint") : t("reinstateHint")}
            reason={{ label: t("reason"), minLength: 3 }}
            confirmLabel={suspend ? t("suspend") : t("reinstate")}
            cancelLabel={common("cancel")}
            destructive={suspend}
            onConfirm={async (note) => {
              await standing.mutateAsync({ suspend, note });
            }}
            error={standing.isError ? errorText(standing.error) : undefined}
            onOpenChange={(open) => open && standing.reset()}
          />
          <RevenueShareDialog professor={p} onSaved={store} />
          {session.can("users.view") ? (
            <Button asChild variant="ghost">
              <Link href={`/users/${p.user_id}`}>{t("account")}</Link>
            </Button>
          ) : null}
        </div>
      </header>

      {p.suspension_reason && state === "suspended" ? (
        <p role="status" className="rounded-md border border-warning/40 bg-warning/10 px-3 py-2 text-sm">
          {t("suspendedBecause", { reason: p.suspension_reason })}
        </p>
      ) : null}

      <section className="grid gap-3 rounded-xl border p-4 sm:grid-cols-3">
        <Fact label={t("columns.phone")}>{p.phone ?? "—"}</Fact>
        <Fact label={t("province")}>{p.province?.name ?? "—"}</Fact>
        <Fact label={t("columns.created")}>{formatDateTime(p.member_since, locale)}</Fact>
        <Fact label={t("columns.students")}>{p.student_count}</Fact>
        <Fact label={t("verifiedAt")}>{p.verified_at ? formatDateTime(p.verified_at, locale) : "—"}</Fact>
        <Fact label={t("revenueShare")}>
          {p.revenue_share_bps === null ? t("platformDefault") : `${p.revenue_share_bps / 100} %`}
        </Fact>
        <Fact label={t("payoutMethod")}>
          {p.payout_method
            ? `${p.payout_method.rail ? rails(p.payout_method.rail as never) : "—"} · ${p.payout_method.phone} · ${p.payout_method.name ?? "—"}`
            : t("noPayoutMethod")}
        </Fact>
        <Fact label={t("payable")}>
          {p.is_payable ? t("yes") : t("no")}
          {p.payout_hold_until
            ? ` · ${t("heldUntil", { date: formatDateTime(p.payout_hold_until, locale) })}`
            : ""}
        </Fact>
      </section>

      {session.can("kyc.view") ? (
        <section id="kyc" className="grid gap-2">
          <h2 className="font-bold">{t("kycTitle")}</h2>
          {submissions.isError ? (
            <p role="alert" className="text-sm text-destructive">
              {errorText(submissions.error)}
            </p>
          ) : submissions.isPending ? (
            <Skeleton className="h-24 w-full" />
          ) : submissions.data.length === 0 ? (
            <p className="text-sm text-muted-foreground">{t("noKyc")}</p>
          ) : (
            submissions.data.map((s) => <KycSubmissionCard key={s.id} submission={s} />)
          )}
        </section>
      ) : null}

      {session.can("catalog.view") ? (
        <section className="grid gap-2">
          <h2 className="font-bold">{t("coursesTitle")}</h2>
          {courses.isError ? (
            <p role="alert" className="text-sm text-destructive">
              {errorText(courses.error)}
            </p>
          ) : courses.isPending ? (
            <Skeleton className="h-16 w-full" />
          ) : courses.data.length === 0 ? (
            <p className="text-sm text-muted-foreground">{t("noCourses")}</p>
          ) : (
            <ul className="grid gap-1 text-sm">
              {courses.data.map((c) => (
                <li key={c.id} className="flex flex-wrap justify-between gap-2 rounded-md border px-3 py-2">
                  <span className="font-semibold">{c.title ?? c.slug}</span>
                  <span className="text-muted-foreground">
                    {courseT(`status.${c.status}` as never)} ·{" "}
                    {c.is_free ? courseT("free") : c.price ? formatMoney(c.price, locale) : "—"}
                    {c.taken_down_at ? ` · ${courseT("takenDown")}` : ""}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </section>
      ) : null}
    </div>
  );
}

/** Revenue-share override in percent (empty = platform default), with a reason. Future sales only. */
function RevenueShareDialog({
  professor,
  onSaved,
}: {
  professor: AdminProfessorResource;
  onSaved: (data: AdminProfessorResource) => void;
}) {
  const t = useTranslations("admin.professors");
  const common = useTranslations("common");
  const errorText = useErrorText();
  const [open, setOpen] = useState(false);
  const [share, setShare] = useState("");
  const [note, setNote] = useState("");

  const text = share.trim().replace(",", ".");
  const pct = text === "" ? null : /^\d{1,3}(\.\d{1,2})?$/.test(text) ? Number(text) : Number.NaN;
  const shareOk = pct === null || (!Number.isNaN(pct) && pct >= 0 && pct <= 100);
  const valid = shareOk && note.trim().length >= 3;

  const save = useMutation({
    mutationFn: async () =>
      success(
        await adminProfessorsUpdate(professor.id, {
          revenue_share_bps: pct === null ? null : Math.round(pct * 100),
          note: note.trim(),
        }),
      ).data,
    onSuccess: (data) => {
      onSaved(data);
      setOpen(false);
    },
  });

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next);
        if (next) {
          setShare(professor.revenue_share_bps === null ? "" : String(professor.revenue_share_bps / 100));
          setNote("");
          save.reset();
        }
      }}
    >
      <DialogTrigger asChild>
        <Button variant="outline">{t("changeShare")}</Button>
      </DialogTrigger>
      <DialogContent closeLabel={common("close")}>
        <DialogHeader>
          <DialogTitle>{t("changeShareTitle", { name: professor.display_name })}</DialogTitle>
          <DialogDescription>{t("changeShareHint")}</DialogDescription>
        </DialogHeader>
        <form
          noValidate
          className="grid gap-4"
          onSubmit={(e: FormEvent) => {
            e.preventDefault();
            if (valid && !save.isPending) save.mutate();
          }}
        >
          <div className="grid gap-2">
            <Label htmlFor="share-pct">{t("sharePct")}</Label>
            <Input
              id="share-pct"
              inputMode="decimal"
              value={share}
              onChange={(e) => setShare(e.target.value)}
            />
            <p className="text-xs text-muted-foreground">{t("shareEmpty")}</p>
            {shareOk ? null : <p className="text-sm text-destructive">{t("badShare")}</p>}
          </div>
          <div className="grid gap-2">
            <Label htmlFor="share-note">{t("reason")}</Label>
            <Textarea
              id="share-note"
              rows={3}
              maxLength={2000}
              value={note}
              onChange={(e) => setNote(e.target.value)}
            />
          </div>
          {save.isError ? (
            <p role="alert" className="text-sm text-destructive">
              {errorText(save.error)}
            </p>
          ) : null}
          <div className="flex justify-end gap-2">
            <Button type="button" variant="ghost" onClick={() => setOpen(false)}>
              {common("cancel")}
            </Button>
            <Button type="submit" disabled={!valid || save.isPending} aria-busy={save.isPending}>
              {t("saveShare")}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="grid gap-0.5">
      <span className="text-xs font-semibold text-muted-foreground">{label}</span>
      <span className="text-sm">{children}</span>
    </div>
  );
}
