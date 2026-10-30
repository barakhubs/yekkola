"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { success } from "@yekkola/api-client";
import {
  adminStaffDestroy,
  adminStaffStore,
  adminStaffUpdate,
  getAdminStaffIndexQueryKey,
  useAdminStaffIndex,
  useAdminStaffPermissions,
} from "@yekkola/api-client/endpoints/staff";
import type { Permission, StaffMemberResource, StaffRequestRole } from "@yekkola/api-client/model";
import { formatDateTime, type Locale } from "@yekkola/i18n";
import { ConfirmDialog } from "@yekkola/ui/blocks/confirm-dialog";
import { PageHeader } from "@yekkola/ui/blocks/page-header";
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
import { useLocale, useTranslations } from "next-intl";
import { type FormEvent, useState } from "react";

import { useSession } from "@/features/auth/session";
import { useErrorText } from "@/features/common/errors";
import { selectClass } from "@/features/common/styles";

const ROLES: StaffRequestRole[] = ["moderator", "admin", "super-admin"];

/**
 * Staff (PRD-09 FR-17): who has back-office access, with their role and any permissions added on top (e.g.
 * finance); invite by phone, change, or end someone's access. The API refuses changes to one's own access and,
 * without `staff.roles`, anything touching a super admin.
 */
export function StaffPage() {
  const t = useTranslations("admin.staff");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const common = useTranslations("common");
  const queryClient = useQueryClient();
  const session = useSession();
  const staff = useAdminStaffIndex({ query: { select: (r) => success(r) } });
  const refresh = () => queryClient.invalidateQueries({ queryKey: getAdminStaffIndexQueryKey() });
  const remove = useMutation({ mutationFn: (id: string) => adminStaffDestroy(id), onSettled: refresh });

  return (
    <div className="mx-auto grid max-w-5xl gap-6 px-4 py-8 sm:px-6">
      <PageHeader title={t("title")} description={t("intro")} actions={<StaffForm onSaved={refresh} />} />

      {staff.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(staff.error)}
        </p>
      ) : (
        <ul className="grid gap-2">
          {(staff.data?.data ?? []).map((member) => (
            <li
              key={member.id}
              className="flex flex-wrap items-start justify-between gap-3 rounded-xl border p-4"
            >
              <div className="grid gap-1">
                <span className="font-semibold">
                  {member.name ?? "—"}{" "}
                  <span className="text-sm font-normal text-muted-foreground">{member.phone}</span>
                </span>
                <span className="text-sm">
                  {member.role ? t(`roles.${member.role}` as never) : "—"}
                  {member.extra_permissions.length > 0 ? ` + ${member.extra_permissions.join(", ")}` : ""}
                </span>
                <span className="text-xs text-muted-foreground">
                  {member.last_seen_at
                    ? t("lastSeen", { date: formatDateTime(member.last_seen_at, locale) })
                    : t("neverSeen")}
                </span>
              </div>
              {member.id === session.user?.id ? (
                <span className="text-xs text-muted-foreground">{t("you")}</span>
              ) : (
                <span className="flex gap-2">
                  <StaffForm member={member} onSaved={refresh} />
                  <ConfirmDialog
                    trigger={
                      <Button size="sm" variant="ghost">
                        {t("remove")}
                      </Button>
                    }
                    title={t("removeConfirm", { name: member.name ?? "—" })}
                    description={t("removeHint")}
                    confirmLabel={t("remove")}
                    cancelLabel={common("cancel")}
                    destructive
                    onConfirm={async () => {
                      await remove.mutateAsync(member.id);
                    }}
                    error={
                      remove.isError && remove.variables === member.id ? errorText(remove.error) : undefined
                    }
                    onOpenChange={(open) => {
                      if (open) remove.reset();
                    }}
                  />
                </span>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

/** Invite (no member) or change a member's role and extra permissions. */
function StaffForm({ member, onSaved }: { member?: StaffMemberResource; onSaved: () => void }) {
  const t = useTranslations("admin.staff");
  const common = useTranslations("common");
  const errorText = useErrorText();
  const permissions = useAdminStaffPermissions({
    query: { select: (r) => success(r).data, staleTime: Infinity },
  });
  const [open, setOpen] = useState(false);
  const [phone, setPhone] = useState("");
  const [role, setRole] = useState<StaffRequestRole>(
    (member?.role as StaffRequestRole | null) ?? "moderator",
  );
  const [extra, setExtra] = useState<string[]>(member?.extra_permissions ?? []);

  const save = useMutation({
    mutationFn: async () => {
      const body = { role, permissions: extra as Permission[] };
      if (member) await adminStaffUpdate(member.id, body);
      else await adminStaffStore({ ...body, phone: phone.trim() });
    },
    onSuccess: () => {
      setOpen(false);
      onSaved();
    },
  });

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next);
        if (next) save.reset();
      }}
    >
      <DialogTrigger asChild>
        <Button size="sm" variant={member ? "outline" : "default"}>
          {member ? t("change") : t("invite")}
        </Button>
      </DialogTrigger>
      <DialogContent closeLabel={common("close")}>
        <DialogHeader>
          <DialogTitle>
            {member ? t("changeTitle", { name: member.name ?? "—" }) : t("inviteTitle")}
          </DialogTitle>
          <DialogDescription>{member ? t("changeHint") : t("inviteHint")}</DialogDescription>
        </DialogHeader>
        <form
          noValidate
          className="grid gap-4"
          onSubmit={(e: FormEvent) => {
            e.preventDefault();
            if (!save.isPending) save.mutate();
          }}
        >
          {member ? null : (
            <div className="grid gap-2">
              <Label htmlFor="staff-phone">{t("phone")}</Label>
              <Input
                id="staff-phone"
                type="tel"
                inputMode="tel"
                value={phone}
                onChange={(e) => setPhone(e.target.value)}
              />
            </div>
          )}
          <div className="grid gap-2">
            <Label htmlFor="staff-role">{t("role")}</Label>
            <select
              id="staff-role"
              className={selectClass}
              value={role}
              onChange={(e) => setRole(e.target.value as StaffRequestRole)}
            >
              {ROLES.map((r) => (
                <option key={r} value={r}>
                  {t(`roles.${r}`)}
                </option>
              ))}
            </select>
          </div>
          <fieldset className="grid gap-2">
            <legend className="mb-1 text-sm font-semibold">{t("extra")}</legend>
            <div className="grid max-h-56 gap-1 overflow-y-auto sm:grid-cols-2">
              {(permissions.data ?? []).map((p) => (
                <label key={p} className="flex items-center gap-2 font-mono text-xs">
                  <input
                    type="checkbox"
                    className="size-4 accent-primary"
                    checked={extra.includes(p)}
                    onChange={(e) =>
                      setExtra(e.target.checked ? [...extra, p] : extra.filter((x) => x !== p))
                    }
                  />
                  {p}
                </label>
              ))}
            </div>
            <p className="text-xs text-muted-foreground">{t("extraHint")}</p>
          </fieldset>
          {save.isError ? (
            <p role="alert" className="text-sm text-destructive">
              {errorText(save.error)}
            </p>
          ) : null}
          <div className="flex justify-end gap-2">
            <Button type="button" variant="ghost" onClick={() => setOpen(false)}>
              {common("cancel")}
            </Button>
            <Button
              type="submit"
              disabled={save.isPending || (!member && !phone.trim())}
              aria-busy={save.isPending}
            >
              {t("save")}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  );
}
