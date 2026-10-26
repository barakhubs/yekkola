"use client";

import { useEffect, useId } from "react";

/**
 * Forms in the editor with changes not yet saved. Leaving the editor's tab (or the page) asks first, so a
 * professor never loses a half-written quiz or description without noticing.
 */
const dirty = new Set<string>();

function warnBeforeUnload(event: BeforeUnloadEvent) {
  event.preventDefault();
}

let guarded = false;
function sync() {
  const active = dirty.size > 0;
  if (active && !guarded) window.addEventListener("beforeunload", warnBeforeUnload);
  if (!active && guarded) window.removeEventListener("beforeunload", warnBeforeUnload);
  guarded = active;
}

/** Registers this form as having unsaved changes while `isDirty` is true. */
export function useUnsavedChanges(isDirty: boolean) {
  const id = useId();

  useEffect(() => {
    if (!isDirty) return;
    dirty.add(id);
    sync();
    return () => {
      dirty.delete(id);
      sync();
    };
  }, [id, isDirty]);
}

export function hasUnsavedChanges(): boolean {
  return dirty.size > 0;
}
