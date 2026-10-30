# apps/web — Next.js (public site, students, professor studio)

## Conventions

- App Router under `src/app/[locale]/` with route groups `(marketing)`, `(auth)`, `(student)`, `(studio)` — see `docs/architecture.md` §2 and §5.2.
- Public (marketing) pages: Server Components, ISR, minimal client JS, SEO metadata + JSON-LD. Must stay fast on slow 3G and low-end phones.
- Student and studio areas: client components behind an auth guard, data via TanStack Query hooks from `@yekkola/api-client`.
- Never call `fetch` directly from components; use generated hooks/fetchers. No business logic (prices, access, limits) — the API decides.
- Product UI in `src/features/<domain>/`; primitives and generic blocks come from `@yekkola/ui`.
- URL state (filters, sort, page) via nuqs. Forms via React Hook Form + Zod schemas from `api-client`.
- All copy through next-intl (French default, English). Money/dates/phones formatted with `@yekkola/i18n` helpers.
- Brand: Nunito font (via `next/font/google`), primary indigo `#2c3892`, amber accent `#fdb73b` via theme tokens only — never white text on amber. Spec: `docs/brand.md`.
- Video: Mux Player + the per-viewer watermark overlay component.

## Commands

- `pnpm --filter @yekkola/web dev` → http://localhost:3000 (copy `.env.example` to `.env.local`; the API runs on :8000)
- `pnpm --filter @yekkola/web build` · `lint` · `typecheck` (runs `next typegen` first)

## Setup notes

- Public pages (`(marketing)`) are Server Components fed by `src/lib/public-api.ts` (ISR: `revalidate` + cache tags `courses`, `course:{slug}`, `professor:{slug}`, `categories`). Course and professor pages render on first visit (`generateStaticParams` returns `[]`). Use `success()` from `@yekkola/api-client` to unwrap generated responses.
- Filters and search are plain GET forms (work without JavaScript); `AutoSubmit` applies selects on change. Mux Player loads only when a preview opens (`next/dynamic`).
- Session: `useSession()` (GET /me; null on 401) drives the header (`AccountNav`) and `RequireSession` guards `(student)` pages (redirect to `/login?next=`). Sign-in posts to the API with the Sanctum cookie; redirect targets go through `safeNext()`.
- Turnstile: `NEXT_PUBLIC_TURNSTILE_SITE_KEY`; empty locally/staging (placeholder token for the API's fake driver).
- Learning view (`/learn/[course]/[lesson]`, `features/learning`): lesson content by type (`MediaLesson` with fresh tokens + stream heartbeat, `DocumentLesson` polling the stamped copy, `QuizLesson`); positions go through `useProgressSync` (15 s batches, flushed on pause/end/hide/leave with `keepalive`). Completion rules are the API's — the view only reports positions and "done".
- Checkout (`/checkout?course=`, `/checkout/[orderId]`, `/orders`, `features/checkout`): every money write goes through `withIdempotencyKey(scope, …)` — the key survives network errors and 5xx (safe replay) and is forgotten once the API gives a final answer (2xx, 400/404/410/422), so the next try is a new action. The order page polls with backoff and only shows "paid" when the API says so; payer numbers come back masked, so retries ask for the number again.
- Studio (`/studio/courses`, `/studio/courses/[id]/(details|curriculum|pricing|review)`, `features/studio`): `(studio)` pages sit behind `RequireSession` + `RequireProfessor` (role from `/me`; the API enforces access). Choices and limits (currencies, price limits/steps, upload sizes, preview limit, default pass mark) come from `GET /professor/authoring-options` — never hard-coded. Curriculum order uses `@dnd-kit/react` (keyboard + pointer, announcements translated); mount a sortable only together with its handle, or dnd-kit makes the whole item a focusable "button". Video/audio uploads live in `upload-store.ts` (UpChunk, outside React) so they survive tab changes and warn before unload.
- Becoming a professor: `/teach` (static marketing page) → `/studio/apply` (`features/application`): four-step form with autosave (draft created by the first save — keep the form mounted), then status, approval (refreshes `/me` so the studio opens), rejection with `reapply_at`. Professor-only pages live in `(studio)/studio/(pro)/` (home with the onboarding checklist, courses, profile, verification, payouts): its layout (`ProfessorShell`) checks the role, gates on the professor agreement (`terms.acceptance_required` → accept the version the API names) and shows the studio nav. `/studio/apply` only needs a session.
- API errors: show `ApiError.title` (translated by the API), branch on `ApiError.code`.
- Unit tests: `pnpm --filter @yekkola/web test` (Vitest, `src/**/*.test.ts`).
- Images: covers/avatars via `next/image`; allowed hosts = API origin + `NEXT_PUBLIC_IMAGE_HOSTS`.

- Locale routing: next-intl with `localePrefix: "as-needed"` — French at `/`, English at `/en`; `src/proxy.ts` (Next 16 middleware) negotiates it. Use `Link`/`redirect`/`useRouter` from `@/i18n/navigation`.
- Every layout and page that renders text calls `setRequestLocale(locale)` so pages stay static.
- Providers (`src/lib/providers.tsx`): TanStack Query (no retries on 4xx), nuqs, API client locale.
- Server Components fetch with generated fetchers; `src/lib/api.server.ts` (imported by the root layout) sends each request's locale. `API_INTERNAL_URL` overrides the API origin for server-side calls.
