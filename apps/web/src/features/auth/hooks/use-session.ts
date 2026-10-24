"use client";

import { ApiError, success } from "@yekkola/api-client";
import { getMeShowQueryKey, useMeShow } from "@yekkola/api-client/endpoints/profile";
import type { UserResource } from "@yekkola/api-client/model";

/**
 * The signed-in user (GET /me). `isSignedOut` only on a 401: a network error or 5xx is `isError`, never a
 * reason to send someone back to sign in (that costs them an SMS).
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
    isLoading: query.isPending && !query.isError,
    isSignedIn: user !== null,
    isSignedOut,
    /** Couldn't tell (offline, server error): show a retry, keep the session. */
    isError: query.isError && !isSignedOut,
    retry: () => query.refetch(),
    /** FR-15: suspended accounts may sign in but only see an explanation. */
    isSuspended: user?.status === "suspended",
    /** FR-04: a name is required before using the platform (certificates, watermark). */
    needsProfile: user !== null && !user.name,
    queryKey: getMeShowQueryKey(),
  };
}
