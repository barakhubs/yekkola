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

- `pnpm --filter @yekkola/admin dev` → http://localhost:3001 (copy `.env.example` to `.env.local`; the API runs on :8000)
- `pnpm --filter @yekkola/admin build` · `lint` · `typecheck` (runs `next typegen` first)

## Setup notes

- Locale routing: next-intl with `localePrefix: "as-needed"` — French at `/`, English at `/en`; `src/proxy.ts` (Next 16 middleware) negotiates it. Use `Link`/`redirect`/`useRouter` from `@/i18n/navigation`.
- Every layout and page that renders text calls `setRequestLocale(locale)` so pages stay static.
- Providers (`src/lib/providers.tsx`): TanStack Query (no retries on 4xx), nuqs, API client locale.
- Sign-in (`/login`, `features/auth`): phone + SMS code like everyone (PRD-01), Turnstile (`NEXT_PUBLIC_TURNSTILE_SITE_KEY`, empty locally), back to `?next=`. `ConsoleShell` (`features/shell`) guards `(console)`: visitors → `/login?next=`; signed-in non-staff (`/me` `is_staff` false) see why and can sign out.
- Navigation and actions follow the API: sidebar items need the permission their routes require (`useSession().can(…)` from `/me` `permissions`); detail screens render decisions from the resource's `can` (e.g. `can.decide`, `can.approve`), never from status rules in the UI.
- Lists: `DataTable` from `@yekkola/ui/blocks/data-table` with `dataTableColumns<Row>().columns([...])`; page, filters and `sort` (`-field` for descending) in the URL via nuqs.
- Screens so far: home queues (`/`), professor applications (`/applications`, `/applications/[id]`), course reviews (`/moderation/courses`, `/moderation/courses/[id]`).
