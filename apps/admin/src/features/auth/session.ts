"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { ApiError, success } from "@yekkola/api-client";
import { authLogout } from "@yekkola/api-client/endpoints/logout";
import { useMeShow } from "@yekkola/api-client/endpoints/profile";
import type { UserResource } from "@yekkola/api-client/model";

import { useRouter } from "@/i18n/navigation";

/**
 * The signed-in user (GET /me). `isSignedOut` only on a 401: a network error or 5xx is `isError`, never a reason
 * to send someone back to sign in.
 */
export function useSession() {
  const query = useMeShow({
    query: {
      select: (response) => success(response).data as UserResource,
      retry: (failures, error) => !(error instanceof ApiError && error.status < 500) && failures < 2,
      staleTime: 5 * 60_000,
    },
  });
  const isSignedOut = query.error instanceof ApiError && query.error.status === 401;
  const user = isSignedOut ? null : (query.data ?? null);

  return {
    user,
    isSignedIn: user !== null,
    isSignedOut,
    isError: query.isError && !isSignedOut,
    retry: () => query.refetch(),
    /** The API's own rule (any staff role); suspended or banned staff are kept out too. */
    isStaff: user !== null && user.is_staff && user.status === "active",
    /** Hide what the API would refuse (PRD-09 FR-02); the API still checks every request. */
    can: (permission: string) => user?.permissions.includes(permission) ?? false,
  };
}

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
      router.replace("/login");
    },
  });
}
