"use client";

import { cn } from "@yekkola/ui/lib/utils";
import { useTranslations } from "next-intl";
import { useState } from "react";

import { PasswordSignIn } from "./password-sign-in";
import { SignInForm } from "./sign-in-form";

/** Staff choose how to sign in: phone + SMS code (like everyone) or email + password (staff only). */
export function SignInChoice() {
  const t = useTranslations("admin.auth");
  const [method, setMethod] = useState<"sms" | "password">("sms");

  return (
    <div className="grid gap-4">
      <div role="tablist" aria-label={t("method")} className="grid grid-cols-2 gap-1 rounded-lg bg-muted p-1">
        {(["sms", "password"] as const).map((m) => (
          <button
            key={m}
            type="button"
            role="tab"
            aria-selected={method === m}
            onClick={() => setMethod(m)}
            className={cn(
              "rounded-md px-3 py-1.5 text-sm font-semibold",
              method === m ? "bg-background shadow-sm" : "text-muted-foreground hover:text-foreground",
            )}
          >
            {t(`methods.${m}`)}
          </button>
        ))}
      </div>
      {method === "sms" ? <SignInForm /> : <PasswordSignIn />}
    </div>
  );
}
