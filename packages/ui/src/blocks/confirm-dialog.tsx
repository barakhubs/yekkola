"use client";

import * as React from "react";

import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from "../components/alert-dialog";
import { Button } from "../components/button";
import { Label } from "../components/label";
import { Textarea } from "../components/textarea";

interface ConfirmDialogProps {
  trigger: React.ReactNode;
  title: React.ReactNode;
  description?: React.ReactNode;
  confirmLabel: React.ReactNode;
  cancelLabel: React.ReactNode;
  /** Destructive and financial actions ask for a typed reason, stored in the audit log by the API. */
  reason?: { label: React.ReactNode; minLength?: number };
  destructive?: boolean;
  /** Why the last confirm failed (the dialog stays open): shown inside the dialog, where it can be seen and heard. */
  error?: React.ReactNode;
  /** E.g. to clear an earlier error when the dialog is opened again. */
  onOpenChange?: (open: boolean) => void;
  /** Resolve to close the dialog; reject (or throw) to keep it open, e.g. on a validation error. */
  onConfirm: (reason: string) => Promise<void> | void;
}

/** Confirmation for actions that are hard to undo (PRD-09: every destructive or financial action). */
function ConfirmDialog({
  trigger,
  title,
  description,
  confirmLabel,
  cancelLabel,
  reason,
  destructive = false,
  onConfirm,
  error,
  onOpenChange,
}: ConfirmDialogProps) {
  const [open, setOpen] = React.useState(false);
  const [text, setText] = React.useState("");
  const [pending, setPending] = React.useState(false);
  const reasonId = React.useId();
  const tooShort = reason !== undefined && text.trim().length < (reason.minLength ?? 3);

  async function confirm() {
    setPending(true);
    try {
      await onConfirm(text.trim());
      setOpen(false);
      setText("");
    } catch {
      // Stay open: the caller shows the error (e.g. a toast or field message).
    } finally {
      setPending(false);
    }
  }

  return (
    <AlertDialog
      open={open}
      onOpenChange={(next) => {
        if (pending) return;
        setOpen(next);
        if (!next) setText("");
        onOpenChange?.(next);
      }}
    >
      <AlertDialogTrigger asChild>{trigger}</AlertDialogTrigger>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>{title}</AlertDialogTitle>
          {description ? <AlertDialogDescription>{description}</AlertDialogDescription> : null}
        </AlertDialogHeader>
        {reason ? (
          <div className="grid gap-2">
            <Label htmlFor={reasonId}>{reason.label}</Label>
            <Textarea id={reasonId} value={text} onChange={(e) => setText(e.target.value)} maxLength={500} />
          </div>
        ) : null}
        {error ? (
          <p role="alert" className="text-sm text-destructive">
            {error}
          </p>
        ) : null}
        <AlertDialogFooter>
          <AlertDialogCancel disabled={pending}>{cancelLabel}</AlertDialogCancel>
          <Button
            variant={destructive ? "destructive" : "default"}
            disabled={pending || tooShort}
            aria-busy={pending}
            onClick={confirm}
          >
            {confirmLabel}
          </Button>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}

export { ConfirmDialog };
export type { ConfirmDialogProps };
