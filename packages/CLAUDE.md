# packages/ — shared front-end packages

| Package | Holds | Rules |
|---|---|---|
| `ui/` | shadcn/ui primitives, generic blocks (DataTable, KpiCard, ChartCard, PageHeader, EmptyState, FileUpload, ConfirmDialog, StatusBadge, MoneyText), theme tokens, brand assets | No domain knowledge. Never imports from apps or `api-client`. |
| `api-client/` | Orval-generated fetchers, TanStack Query hooks, Zod schemas | **Generated — do not edit by hand.** Regenerate from the API's OpenAPI spec. |
| `i18n/` | `fr.json` / `en.json` message catalogues (namespaced), money/date/phone formatters | French is the default locale. |
| `config/` | Shared tsconfig, ESLint, Tailwind preset, Prettier | |

Brand tokens (light + dark): primary `#2c3892` → `--primary` (white foreground); amber `#fdb73b` → `--brand-accent` (indigo foreground); shadcn `--secondary` stays neutral. See `project-context.md` → Brand.

## Commands

Not scaffolded yet.
