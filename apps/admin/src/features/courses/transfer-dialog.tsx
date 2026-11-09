"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { adminCoursesTransfer, getAdminCoursesIndexQueryKey } from "@yekkola/api-client/endpoints/course";
import type { AdminCourseResource } from "@yekkola/api-client/model";
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

import { useErrorText } from "@/features/common/errors";
import { ProfessorPicker } from "@/features/promotions/pickers";

/**
 * Move a course to another professor (PRD-09 FR-08, super admin). The API decides what follows: future sales earn
 * the new professor, the old professor's coupons on it are switched off and it leaves their bundles.
 */
export function TransferDialog({ course }: { course: AdminCourseResource }) {
  const t = useTranslations("admin.courses.transfer");
  const common = useTranslations("common");
  const errorText = useErrorText();
  const queryClient = useQueryClient();
  const [open, setOpen] = useState(false);
  const [target, setTarget] = useState<{ id: string; name: string } | null>(null);
  const [note, setNote] = useState("");

  const save = useMutation({
    mutationFn: () =>
      adminCoursesTransfer(course.id, { professor_profile_id: target?.id ?? "", note: note.trim() }),
    onSuccess: async () => {
      setOpen(false);
      await queryClient.invalidateQueries({ queryKey: getAdminCoursesIndexQueryKey() });
    },
  });
  const valid = target !== null && target.id !== course.professor.id && note.trim().length >= 3;

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next);
        if (next) {
          setTarget(null);
          setNote("");
          save.reset();
        }
      }}
    >
      <DialogTrigger asChild>
        <Button size="sm" variant="outline">
          {t("action")}
        </Button>
      </DialogTrigger>
      <DialogContent closeLabel={common("close")} className="max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{t("title", { title: course.title ?? course.slug })}</DialogTitle>
          <DialogDescription>{t("hint", { name: course.professor.display_name })}</DialogDescription>
        </DialogHeader>
        <form
          noValidate
          className="grid gap-4"
          onSubmit={(e: FormEvent) => {
            e.preventDefault();
            if (valid && !save.isPending) save.mutate();
          }}
        >
          {target ? (
            <div className="flex items-center justify-between gap-2 rounded-md border px-3 py-2 text-sm">
              <span className="grid">
                <span className="text-xs text-muted-foreground">{t("newOwner")}</span>
                <span className="font-semibold">{target.name}</span>
              </span>
              <Button type="button" size="sm" variant="ghost" onClick={() => setTarget(null)}>
                {t("change")}
              </Button>
            </div>
          ) : (
            <ProfessorPicker
              label={t("search")}
              empty={t("noResults")}
              onPick={(p) => setTarget({ id: p.id, name: p.display_name })}
            />
          )}
          {target && target.id === course.professor.id ? (
            <p className="text-sm text-destructive">{t("sameOwner")}</p>
          ) : null}
          <div className="grid gap-2">
            <Label htmlFor="transfer-note">{t("reason")}</Label>
            <Textarea
              id="transfer-note"
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
              {t("confirm")}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  );
}
