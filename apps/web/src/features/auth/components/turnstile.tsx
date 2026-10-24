"use client";

import Script from "next/script";
import { useEffect, useRef } from "react";

declare global {
  interface Window {
    turnstile?: {
      render: (el: HTMLElement, options: Record<string, unknown>) => string;
      reset: (id: string) => void;
      remove: (id: string) => void;
    };
  }
}

const SITE_KEY = process.env.NEXT_PUBLIC_TURNSTILE_SITE_KEY ?? "";

/** Placeholder token for local/staging, where the API uses the fake bot-check driver (production refuses it). */
export const DEV_BOT_TOKEN = "dev-token";

interface TurnstileProps {
  /** Called with a token for the OTP request (PRD-01 FR-10), or null when it expires. */
  onToken: (token: string | null) => void;
  locale: string;
  label: string;
}

/**
 * Cloudflare Turnstile on the web OTP request. Without a site key (local/staging) it hands back a placeholder
 * token at once.
 */
export function Turnstile({ onToken, locale, label }: TurnstileProps) {
  const container = useRef<HTMLDivElement>(null);
  const widget = useRef<string | null>(null);
  const callback = useRef(onToken);
  useEffect(() => {
    callback.current = onToken;
  }, [onToken]);

  useEffect(() => {
    if (!SITE_KEY) {
      callback.current(DEV_BOT_TOKEN);
    }
  }, []);

  const render = () => {
    if (!SITE_KEY || !container.current || !window.turnstile || widget.current) return;
    widget.current = window.turnstile.render(container.current, {
      sitekey: SITE_KEY,
      language: locale,
      appearance: "interaction-only",
      callback: (token: string) => callback.current(token),
      "expired-callback": () => callback.current(null),
      "error-callback": () => callback.current(null),
    });
  };

  useEffect(() => {
    render();
    return () => {
      if (widget.current && window.turnstile) window.turnstile.remove(widget.current);
      widget.current = null;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps -- render once per mount
  }, []);

  if (!SITE_KEY) {
    return null;
  }

  return (
    <>
      <Script
        src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit"
        strategy="afterInteractive"
        onLoad={render}
      />
      <div ref={container} aria-label={label} />
    </>
  );
}
