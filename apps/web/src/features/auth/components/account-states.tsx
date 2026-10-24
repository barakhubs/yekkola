"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { ApiError } from "@yekkola/api-client";
import { authLogout } from "@yekkola/api-client/endpoints/logout";
import { EmptyState } from "@yekkola/ui/blocks/empty-state";
import { Button } from "@yekkola/ui/components/button";
import { ShieldAlert, WifiOff } from "lucide-react";
import { useTranslations } from "next-intl";

import { useRouter } from "@/i18n/navigation";

/** Signs out; local state is cleared only once the API has ended the session (shared devices). */
export function useSignOut() {
  const queryClient = useQueryClient();
  const router = useRouter();

  return useMutation({
    mutationFn: async () => {
      try {
        await authLogout();
      } catch (error) {
        // Already signed out on the server: same outcome.
        if (!(error instanceof ApiError && error.status === 401)) throw error;
      }
    },
    onSuccess: () => {
      queryClient.clear();
      router.replace("/");
    },
  });
}

/** FR-15: a suspended account can only read why and sign out. */
export function SuspendedNotice() {
  const t = useTranslations("web.auth");
  const errors = useTranslations("errors");
  const signOut = useSignOut();

  return (
    <div className="mx-auto max-w-xl px-4 py-16">
      <EmptyState
        icon={<ShieldAlert />}
        title={t("suspendedTitle")}
        description={t("suspendedBody")}
        action={
          <div className="grid justify-items-center gap-2">
            <Button variant="outline" onClick={() => signOut.mutate()} disabled={signOut.isPending}>
              {t("signOut")}
            </Button>
            {signOut.isError ? (
              <p role="alert" className="text-sm text-destructive">
                {errors("network")}
              </p>
            ) : null}
          </div>
        }
      />
    </div>
  );
}

/** Offline or server error while checking the session: retry, never a forced sign-in. */
export function SessionUnavailable({ onRetry }: { onRetry: () => void }) {
  const errors = useTranslations("errors");
  const common = useTranslations("common");

  return (
    <div className="mx-auto max-w-xl px-4 py-16">
      <EmptyState
        icon={<WifiOff />}
        title={errors("network")}
        action={
          <Button variant="outline" onClick={onRetry}>
            {common("retry")}
          </Button>
        }
      />
    </div>
  );
}
