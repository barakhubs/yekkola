"use client";

import { EmptyState } from "@yekkola/ui/blocks/empty-state";
import { Button } from "@yekkola/ui/components/button";
import { useTranslations } from "next-intl";
import { useEffect, useState } from "react";

/**
 * Something failed while rendering (often the API being busy — a rate limit on a shared connection — or briefly
 * unreachable). Production hides the cause, so one calm message: wait a moment, then try again.
 */
export default function Error({ reset }: { error: Error & { digest?: string }; reset: () => void }) {
  const t = useTranslations("web.errorPage");
  const [wait, setWait] = useState(10);

  useEffect(() => {
    if (wait <= 0) return;
    const timer = window.setTimeout(() => setWait((s) => s - 1), 1000);
    return () => window.clearTimeout(timer);
  }, [wait]);

  return (
    <div className="mx-auto max-w-xl px-4 py-16">
      <EmptyState
        title={t("title")}
        description={t("body")}
        action={
          <Button onClick={() => reset()} disabled={wait > 0}>
            {wait > 0 ? t("retryIn", { seconds: wait }) : t("retry")}
          </Button>
        }
      />
    </div>
  );
}
