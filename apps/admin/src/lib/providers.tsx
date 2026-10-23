"use client";

import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { ApiError, configureApiClient } from "@yekkola/api-client";
import { useLocale } from "next-intl";
import { NuqsAdapter } from "nuqs/adapters/next/app";
import { useState, type ReactNode } from "react";

function makeQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: 60_000,
        // Client errors (4xx) won't change on retry; network blips and 5xx get two more tries.
        retry: (failures, error) =>
          failures < 2 && !(error instanceof ApiError && error.status >= 400 && error.status < 500),
      },
    },
  });
}

/** Client-side providers: server state (TanStack Query), URL state (nuqs), and the API client's locale. */
export function Providers({ children }: { children: ReactNode }) {
  const locale = useLocale();
  // Browser only: during SSR this module is shared by all requests, which use the server config.
  if (typeof window !== "undefined") {
    configureApiClient({ locale: () => locale });
  }
  const [queryClient] = useState(makeQueryClient);

  return (
    <QueryClientProvider client={queryClient}>
      <NuqsAdapter>{children}</NuqsAdapter>
    </QueryClientProvider>
  );
}
