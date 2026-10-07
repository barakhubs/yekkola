# apps/web — Next.js (public site, students, professor studio)

## Conventions

- App Router under `src/app/[locale]/` with route groups `(marketing)`, `(auth)`, `(student)`, `(studio)` — see `docs/architecture.md` §2 and §5.2.
- Public (marketing) pages: Server Components, ISR, minimal client JS, SEO metadata + JSON-LD. Must stay fast on slow 3G and low-end phones.
- Student and studio areas: client components behind an auth guard, data via TanStack Query hooks from `@yekkola/api-client`.
- Never call `fetch` directly from components; use generated hooks/fetchers. No business logic (prices, access, limits) — the API decides.
- Product UI in `src/features/<domain>/`; primitives and generic blocks come from `@yekkola/ui`.
- URL state (filters, sort, page) via nuqs. Forms via React Hook Form + Zod schemas from `api-client`.
- All copy through next-intl (French default, English). Money/dates/phones formatted with `@yekkola/i18n` helpers.
- Brand: primary indigo `#2c3892`, amber accent `#fdb73b` via theme tokens only — never white text on amber.
- Video: Mux Player + the per-viewer watermark overlay component.

## Commands

Not scaffolded yet.
