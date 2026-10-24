import { defineConfig } from "orval";

/**
 * Generated from the API's OpenAPI spec (api/openapi.json, produced by Scramble). Never edit src/generated
 * by hand: change the Laravel endpoint, run `composer openapi` in api/, then `pnpm api-client:generate`.
 * CI (contract.yml) fails if the committed client is out of date.
 */
const input = { target: "../../api/openapi.json" };

export default defineConfig({
  client: {
    input,
    output: {
      mode: "tags-split",
      client: "react-query",
      httpClient: "fetch",
      target: "src/generated/endpoints",
      schemas: "src/generated/model",
      clean: true,
      override: {
        mutator: { path: "src/fetcher.ts", name: "apiFetch" },
        query: { useQuery: true, useSuspenseQuery: false, signal: true },
      },
    },
  },
  zod: {
    input,
    output: {
      mode: "tags-split",
      client: "zod",
      target: "src/generated/endpoints",
      fileExtension: ".zod.ts",
    },
  },
});
