"use client";

import { ApiError } from "@yekkola/api-client";
import { useTranslations } from "next-intl";
import { useCallback } from "react";

/** The API's translated reason (`ApiError.title`), or a generic / network message. */
export function useErrorText() {
  const errors = useTranslations("errors");

  return useCallback(
    (error: unknown) =>
      error instanceof ApiError
        ? (error.fieldErrors().note ?? error.title ?? errors("generic"))
        : errors("network"),
    [errors],
  );
}
