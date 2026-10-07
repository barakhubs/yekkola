# apps/admin — Next.js back office

For admins, moderators, and finance staff. Requirements: `docs/prd/09-back-office.md`; structure: `docs/architecture.md` §5.3.

## Conventions

- App Router under `src/app/[locale]/` with `(auth)` and `(console)` groups. Almost entirely client components behind auth; no SEO.
- Only consumes the admin API. No business logic or permission decisions in the UI beyond hiding what the API would refuse.
- List pages use the shared `DataTable` (server pagination/sort/filter, URL-synced with nuqs, column visibility, bulk actions, server-side CSV export).
- Detail pages: status header with primary actions, tabs, activity timeline.
- Dashboards: KPI cards + shadcn charts fed by pre-aggregated stats endpoints.
- Destructive or financial actions always use `ConfirmDialog` with a typed reason.
- French and English; desktop-first, usable on tablet.

## Commands

Not scaffolded yet.
