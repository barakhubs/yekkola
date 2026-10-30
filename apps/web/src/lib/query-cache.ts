import type { QueryClient, QueryKey } from "@tanstack/react-query";

/**
 * Stores a resource the API just answered under a generated query's key. The cache holds the whole response
 * (`{ data: { data }, status, headers }`), which the hooks' `select` unwraps — not the bare resource.
 */
export function setResource(queryClient: QueryClient, queryKey: QueryKey, resource: unknown) {
  queryClient.setQueryData(queryKey, { data: { data: resource }, status: 200, headers: new Headers() });
}
