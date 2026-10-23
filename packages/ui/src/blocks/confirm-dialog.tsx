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
    } finally {
      setPending(false);
    }
  }

  return (
    <AlertDialog open={open} onOpenChange={(next) => !pending && setOpen(next)}>
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
