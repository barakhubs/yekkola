---
name: frontend-feature
description: Build a page or feature in the Yekkola Next.js apps (apps/web or apps/admin) using shadcn/ui, generated API hooks, TanStack Table, React Hook Form + Zod, and next-intl. Use for any UI work under apps/ or packages/ui.
---

# Build a front-end feature

Read the app's `CLAUDE.md` and the PRD first. Brand rules: `project-context.md` → Brand.

1. **API first.** Confirm the endpoints exist in `packages/api-client` (generated). If not, build them with the `api-endpoint` skill — never fake data shapes or call `fetch` directly.
2. **Route** under `src/app/[locale]/(group)/...`. Public marketing pages = Server Components + ISR + metadata/JSON-LD. Authenticated pages = client components behind the auth guard.
3. **Feature code** in `src/features/<domain>/`: `components/`, `hooks/` (wrap generated hooks if needed), `schemas/` (extend generated Zod).
4. **Primitives from `@yekkola/ui`.** If a generic block is missing (table, KPI card, empty state…), add it to `packages/ui` without domain knowledge.
5. **Lists:** `DataTable` with server-side pagination/sort/filter, state in the URL via nuqs, column visibility, bulk actions where useful.
6. **Forms:** React Hook Form + Zod; map API validation errors (`errors.{field}`) onto fields; disable submit while pending; toast on success.
7. **States:** loading (skeletons), empty, error (with retry), and permission-denied for every data view.
8. **i18n:** every string in fr + en catalogues; money/dates/phones via shared formatters.
9. **Styling:** theme tokens only (`primary` indigo, `brand-accent` amber). Never white text on amber. Mobile-first in apps/web.
10. **Tests:** component tests for logic-heavy pieces; add/extend a Playwright journey for critical flows.

Done when: typecheck + lint pass, works at 360 px width (web), both locales render, no direct `fetch`.
