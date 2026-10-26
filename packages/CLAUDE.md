# packages/ — shared front-end packages

| Package       | Holds                                                                                                                                                                       | Rules                                                                        |
| ------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------- |
| `ui/`         | shadcn/ui primitives, generic blocks (DataTable, KpiCard, ChartCard, PageHeader, EmptyState, FileUpload, ConfirmDialog, StatusBadge, MoneyText), theme tokens, brand assets | No domain knowledge. Never imports from apps or `api-client`.                |
| `api-client/` | Orval-generated fetchers, TanStack Query hooks, Zod schemas                                                                                                                 | **Generated — do not edit by hand.** Regenerate from the API's OpenAPI spec. |
| `i18n/`       | `fr.json` / `en.json` message catalogues (namespaced), money/date/phone formatters                                                                                          | French is the default locale.                                                |
| `config/`     | Shared tsconfig, ESLint, Tailwind preset, Prettier                                                                                                                          |                                                                              |

Brand: Nunito everywhere; tokens (light + dark): primary `#2c3892` → `--primary` (white foreground); amber `#fdb73b` → `--brand-accent` (indigo foreground); shadcn `--secondary` stays neutral. Full spec: `docs/brand.md`.

## Usage

- Workspace packages ship TypeScript source (no build step); apps list them in `transpilePackages`.
- `@yekkola/ui`: import per file — `@yekkola/ui/components/button`, `@yekkola/ui/blocks/page-header`, `@yekkola/ui/lib/utils`. `blocks/data-table` wraps TanStack Table v9 for server-paged, server-sorted lists (`dataTableColumns<Row>().columns([...])`, labels passed in). Apps import `@yekkola/ui/styles/globals.css` (brand tokens; `indigo-*`/`amber-*` are the brand scales). Blocks take every label as a prop — no copy inside `ui`.
- `@yekkola/i18n`: `locales`, `defaultLocale`, `loadMessages(locale)`, `formatMoney`, `formatDate`, `formatDateTime`, `formatPhone`, `formatDuration`. Add keys to **both** `messages/fr.json` and `messages/en.json` (French first); apps type keys against `fr.json`.
- `@yekkola/api-client`: hooks and fetchers from `@yekkola/api-client/endpoints` (or `/endpoints/<tag>`), Zod schemas from `/zod/<tag>`, types from the root. Every call goes through `apiFetch` (`src/fetcher.ts`, hand-written): `/api/v1` prefix, `Accept-Language`, Sanctum cookie + XSRF for unsafe methods, non-2xx → `ApiError` (`status`, `code`, `fieldErrors()`). Apps call `configureApiClient` once (server: per-request locale; browser: in `Providers`).
- Generated code (`api-client/src/generated`) is never edited — fix the Laravel endpoint, `composer openapi`, `pnpm api-client:generate`.

## Commands

From the repo root: `pnpm lint`, `pnpm typecheck`, `pnpm test` (Vitest: i18n formatters, api-client fetcher), `pnpm api-client:generate`.
