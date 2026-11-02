"use client";

import { useMutation } from "@tanstack/react-query";
import { success } from "@yekkola/api-client";
import { reportsStore } from "@yekkola/api-client/endpoints/report";
import { Button } from "@yekkola/ui/components/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@yekkola/ui/components/dialog";
import { Label } from "@yekkola/ui/components/label";
import { Textarea } from "@yekkola/ui/components/textarea";
import { Flag } from "lucide-react";
import { useTranslations } from "next-intl";
import { type FormEvent, useId, useState } from "react";

import { useSession } from "@/features/auth/hooks/use-session";
import { useErrorText } from "@/features/studio/hooks";
import { Link } from "@/i18n/navigation";

const REASONS = ["spam", "abusive", "inappropriate", "misleading", "copyright", "fraud", "other"] as const;
type Reason = (typeof REASONS)[number];

interface ReportButtonProps {
  type: "course" | "review" | "professor";
  id: string;
  /** Where to come back to after signing in. */
  returnTo: string;
}

/**
 * Report a course, review or professor to the moderators (PRD-06 FR-14). Signed-out visitors are sent to sign in
 * first; the API refuses reports on one's own content and keeps one open report per person and item.
 */
export function ReportButton({ type, id, returnTo }: ReportButtonProps) {
  const t = useTranslations("web.report");
  const errorText = useErrorText();
  const { isSignedIn, isLoading } = useSession();
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState<Reason | null>(null);
  const [details, setDetails] = useState("");
  const formId = useId();
  const report = useMutation({
    mutationFn: async () =>
      success(await reportsStore({ type, id, reason: reason ?? "other", details: details.trim() || null })),
  });

  const trigger = (
    <Button variant="link" size="sm" className="h-auto gap-1 px-0 text-xs text-muted-foreground">
      <Flag aria-hidden className="size-3.5" />
      {t(`trigger.${type}`)}
    </Button>
  );

  if (isLoading) {
    return null;
  }
  if (!isSignedIn) {
    return (
      <Button asChild variant="link" size="sm" className="h-auto gap-1 px-0 text-xs text-muted-foreground">
        <Link href={`/login?next=${encodeURIComponent(returnTo)}`}>
          <Flag aria-hidden className="size-3.5" />
          {t(`trigger.${type}`)}
        </Link>
      </Button>
    );
  }

  const detailsRequired = reason === "other";

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next);
        if (next) {
          report.reset();
          setReason(null);
          setDetails("");
        }
      }}
    >
      <DialogTrigger asChild>{trigger}</DialogTrigger>
      <DialogContent closeLabel={t("close")}>
        <DialogHeader>
          <DialogTitle>{t(`title.${type}`)}</DialogTitle>
          <DialogDescription>{report.isSuccess ? t("thanks") : t("intro")}</DialogDescription>
        </DialogHeader>
        {report.isSuccess ? (
          <div className="flex justify-end">
            <Button onClick={() => setOpen(false)}>{t("close")}</Button>
          </div>
        ) : (
          <form
            className="grid gap-4"
            onSubmit={(e: FormEvent) => {
              e.preventDefault();
              report.mutate();
            }}
          >
            <fieldset className="grid gap-2">
              <legend className="mb-1 text-sm font-semibold">{t("reason")}</legend>
              {REASONS.map((r) => (
                <label key={r} className="flex items-center gap-2 text-sm">
                  <input
                    type="radio"
                    name={`${formId}-reason`}
                    value={r}
                    checked={reason === r}
                    onChange={() => setReason(r)}
                    className="size-4 accent-primary"
                  />
                  {t(`reasons.${r}`)}
                </label>
              ))}
            </fieldset>
            <div className="grid gap-2">
              <Label htmlFor={`${formId}-details`}>
                {detailsRequired ? t("detailsRequired") : t("details")}
              </Label>
              <Textarea
                id={`${formId}-details`}
                value={details}
                maxLength={1000}
                required={detailsRequired}
                onChange={(e) => setDetails(e.target.value)}
              />
            </div>
            {report.isError ? (
              <p role="alert" className="text-sm text-destructive">
                {errorText(report.error)}
              </p>
            ) : null}
            <div className="flex justify-end gap-2">
              <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                {t("cancel")}
              </Button>
              <Button
                type="submit"
                disabled={reason === null || (detailsRequired && details.trim() === "") || report.isPending}
                aria-busy={report.isPending}
              >
                {t("submit")}
              </Button>
            </div>
          </form>
        )}
      </DialogContent>
    </Dialog>
  );
}
