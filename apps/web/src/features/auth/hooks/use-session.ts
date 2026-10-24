"use client";

import { ApiError, success } from "@yekkola/api-client";
import { getMeShowQueryKey, useMeShow } from "@yekkola/api-client/endpoints/profile";
import type { UserResource } from "@yekkola/api-client/model";

/**
 * The signed-in user (GET /me), or null when signed out (401). Shared cache: sign-in and sign-out update it.
 */
export function useSession() {
  const query = useMeShow({
    query: {
      select: (response) => success(response).data as UserResource,
      retry: (failures, error) => !(error instanceof ApiError && error.status < 500) && failures < 2,
      staleTime: 5 * 60_000,
    },
  });
  const signedOut = query.error instanceof ApiError && query.error.status === 401;

  return {
    user: signedOut ? null : (query.data ?? null),
    isLoading: query.isPending && !signedOut,
    isSignedIn: Boolean(query.data) && !signedOut,
    queryKey: getMeShowQueryKey(),
  };
}
