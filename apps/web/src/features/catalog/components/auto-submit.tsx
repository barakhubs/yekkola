"use client";

import { useEffect } from "react";

/** Submits a GET form as soon as one of its selects changes (progressive enhancement for the filters). */
export function AutoSubmit({ formId }: { formId: string }) {
  useEffect(() => {
    const form = document.getElementById(formId);
    if (!(form instanceof HTMLFormElement)) return;

    const submit = (event: Event) => {
      if (event.target instanceof HTMLSelectElement) {
        // Drop empty fields so URLs stay short and shareable.
        for (const select of Array.from(form.querySelectorAll("select"))) {
          select.disabled = select.value === "";
        }
        form.requestSubmit();
      }
    };
    form.addEventListener("change", submit);

    return () => form.removeEventListener("change", submit);
  }, [formId]);

  return null;
}
