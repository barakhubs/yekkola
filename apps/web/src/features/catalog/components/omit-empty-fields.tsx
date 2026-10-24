"use client";

import { useEffect } from "react";

/** Leaves empty fields out of a GET form's URL, so links stay short and shareable (without disabling fields). */
export function OmitEmptyFields({ formId }: { formId: string }) {
  useEffect(() => {
    const form = document.getElementById(formId);
    if (!(form instanceof HTMLFormElement)) return;

    const clean = (event: FormDataEvent) => {
      for (const [key, value] of Array.from(event.formData.entries())) {
        if (value === "") event.formData.delete(key);
      }
    };
    form.addEventListener("formdata", clean);

    return () => form.removeEventListener("formdata", clean);
  }, [formId]);

  return null;
}
