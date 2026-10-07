# Yekkola

@project-context.md

## Working rules

- Before building a feature, read its PRD in `docs/prd/` and follow `docs/architecture.md` (schema, endpoints, file structure). Reference requirement IDs (e.g. `PRD-05 FR-07`) in commits and PRs.
- If you change the schema, an endpoint, or the structure, update `docs/architecture.md` in the same change. `project-context.md` wins when docs disagree.
- Never hard-code values listed under *Platform settings* or *Open decisions* in `project-context.md` — they are admin settings or undecided; ask.
- Business logic lives only in the API. Front ends (web, admin, mobile) display and submit.
- Any API change: regenerate the OpenAPI spec and `packages/api-client` in the same change.
- All user-facing text goes through translations (French default, English). No hard-coded copy.
- Do not privilege Kinshasa or any city/province in copy, defaults, or data.
- External services (Mux, payments, SMS) are only reached through their interfaces (`VideoProvider`, `PaymentGateway`, `SmsSender`); tests use the fake drivers.

## Layout

| Folder | What | Notes |
|---|---|---|
| `api/` | Laravel API | See `api/CLAUDE.md` |
| `apps/web/` | Next.js — public site, students, professor studio | See `apps/web/CLAUDE.md` |
| `apps/admin/` | Next.js — back office | See `apps/admin/CLAUDE.md` |
| `packages/` | Shared front-end packages (ui, api-client, i18n, config) | See `packages/CLAUDE.md` |
| `mobile/` | Flutter app (phase 3) | See `mobile/CLAUDE.md` |
| `docs/` | Architecture, PRDs, runbooks | |

## Commands

Not scaffolded yet. Add install, dev, test, lint, and build commands here once the repo is set up.
