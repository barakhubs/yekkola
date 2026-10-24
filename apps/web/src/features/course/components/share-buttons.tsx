"use client";

import { Button } from "@yekkola/ui/components/button";
import { Check, Link2, MessageCircle } from "lucide-react";
import { useTranslations } from "next-intl";
import { useState } from "react";

/** WhatsApp first, then copy link (PRD-04 FR-13). */
export function ShareButtons({ url, text }: { url: string; text: string }) {
  const t = useTranslations("web.course");
  const [copied, setCopied] = useState(false);

  async function copy() {
    try {
      await navigator.clipboard.writeText(url);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 2_000);
    } catch {
      window.prompt(t("copyLink"), url);
    }
  }

  return (
    <div className="flex flex-wrap items-center gap-2" role="group" aria-label={t("share")}>
      <Button asChild variant="outline" size="sm">
        <a
          href={`https://wa.me/?text=${encodeURIComponent(`${text} ${url}`)}`}
          target="_blank"
          rel="noopener noreferrer"
        >
          <MessageCircle aria-hidden />
          {t("shareWhatsApp")}
        </a>
      </Button>
      <Button variant="outline" size="sm" onClick={copy} aria-live="polite">
        {copied ? <Check aria-hidden /> : <Link2 aria-hidden />}
        {copied ? t("linkCopied") : t("copyLink")}
      </Button>
    </div>
  );
}
