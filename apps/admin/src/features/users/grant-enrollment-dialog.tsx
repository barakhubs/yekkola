"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { success } from "@yekkola/api-client";
import {
  adminUsersEnrollmentsStore,
  getAdminUsersIndexQueryKey,
  getAdminUsersShowQueryKey,
} from "@yekkola/api-client/endpoints/user";
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
import { useTranslations } from "next-intl";
import { type FormEvent, useState } from "react";

import { useSession } from "@/features/auth/session";
import { useErrorText } from "@/features/common/errors";
import { CoursePicker } from "@/features/promotions/pickers";

/**
 * Give the account access to a course (PRD-09 FR-04): no order, no payment, no ledger entry; the reason goes to
 * the audit log. Free and paid published courses can be granted.
 */
export function GrantEnrollmentDialog({ userId, name }: { userId: string; name: string }) {
  const t = useTranslations("admin.users.grant");
  const common = useTranslations("common");
  const errorText = useErrorText();
  const session = useSession();
  const queryClient = useQueryClient();
  const [open, setOpen] = useState(false);
  const [course, setCourse] = useState<{ id: string; title: string } | null>(null);
  const [note, setNote] = useState("");

  const grant = useMutation({
    mutationFn: async () =>
      success(await adminUsersEnrollmentsStore(userId, { course_id: course?.id ?? "", note: note.trim() }))
        .data,
    onSuccess: async (data) => {
      queryClient.setQueryData(getAdminUsersShowQueryKey(userId), {
        data: { data },
        status: 200,
        headers: new Headers(),
      });
      setOpen(false);
      await queryClient.invalidateQueries({ queryKey: getAdminUsersIndexQueryKey() });
    },
  });
  const valid = course !== null && note.trim().length >= 3;

  // The course search uses the courses list (`catalog.view`).
  if (!session.can("catalog.view")) return null;

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next);
        if (next) {
          setCourse(null);
          setNote("");
          grant.reset();
        }
      }}
    >
      <DialogTrigger asChild>
        <Button variant="outline">{t("action")}</Button>
      </DialogTrigger>
      <DialogContent closeLabel={common("close")} className="max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{t("title", { name })}</DialogTitle>
          <DialogDescription>{t("hint")}</DialogDescription>
        </DialogHeader>
        <form
          noValidate
          className="grid gap-4"
          onSubmit={(e: FormEvent) => {
            e.preventDefault();
            if (valid && !grant.isPending) grant.mutate();
          }}
        >
          {course ? (
            <div className="flex items-center justify-between gap-2 rounded-md border px-3 py-2 text-sm">
              <span className="grid">
                <span className="text-xs text-muted-foreground">{t("course")}</span>
                <span className="font-semibold">{course.title}</span>
              </span>
              <Button type="button" size="sm" variant="ghost" onClick={() => setCourse(null)}>
                {t("change")}
              </Button>
            </div>
          ) : (
            <CoursePicker
              label={t("search")}
              empty={t("noResults")}
              includeFree
              onPick={(c) => setCourse({ id: c.id, title: c.title ?? c.slug })}
            />
          )}
          <div className="grid gap-2">
            <Label htmlFor="grant-note">{t("reason")}</Label>
            <Textarea
              id="grant-note"
              rows={3}
              maxLength={2000}
              value={note}
              onChange={(e) => setNote(e.target.value)}
            />
          </div>
          {grant.isError ? (
            <p role="alert" className="text-sm text-destructive">
              {errorText(grant.error)}
            </p>
          ) : null}
          <div className="flex justify-end gap-2">
            <Button type="button" variant="ghost" onClick={() => setOpen(false)}>
              {common("cancel")}
            </Button>
            <Button type="submit" disabled={!valid || grant.isPending} aria-busy={grant.isPending}>
              {t("confirm")}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  );
}
