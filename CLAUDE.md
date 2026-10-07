# Yekkola

@project-context.md

## Working rules

- Before building a feature, read its PRD in `docs/prd/` and follow `docs/architecture.md` (schema, endpoints, file structure). Reference requirement IDs (e.g. `PRD-05 FR-07`) in commits and PRs.
- If you change the schema, an endpoint, or the structure, update `docs/architecture.md` in the same change. `project-context.md` wins when docs disagree.
- **Work autonomously.** Don't hand tasks to the user or wait for go-aheads — plan, build, test, review, commit, push, and open the PR yourself. The only user step is merging PRs.
- Never hard-code values listed under *Platform settings* or *Open decisions* in `project-context.md`. Don't block on them either: implement behind a setting/interface with a safe default, document the default, and add a `TODO.md` item to confirm it.
- Business logic lives only in the API. Front ends (web, admin, mobile) display and submit.
- Any API change: regenerate the OpenAPI spec and `packages/api-client` in the same change.
- All user-facing text goes through translations (French default, English). No hard-coded copy.
- Do not privilege Kinshasa or any city/province in copy, defaults, or data.
- External services (Mux, payments, SMS) are only reached through their interfaces (`VideoProvider`, `PaymentGateway`, `SmsSender`); tests use the fake drivers.

## TODO list

`TODO.md` is the single start-to-finish task list. **Keep it current on every piece of work**, in the same branch/PR: mark tasks `[~]` when starting and `[x]` (with PR number) when done, add newly discovered work under the right phase, update the *Now* section and *Last updated*. See "How to update" at the bottom of `TODO.md`.

## Git workflow

- **Every feature, fix, or change goes on its own branch** — never commit work directly to `main`.
- **Before creating a branch:** switch to `main` and pull (`git checkout main && git pull --ff-only origin main`). Only branch from an up-to-date `main`.
- **One open branch at a time.** Before starting a new branch, check for unmerged work (`git branch --no-merged main`, `gh pr list --author @me --state open`). Unpushed work → finish it with `/open-pr`. A PR waiting to be merged → report it and don't start another branch.
- Branch names: `feature/<short-name>`, `fix/<short-name>`, `chore/<short-name>`, `docs/<short-name>` (include the PRD ID when relevant, e.g. `feature/prd-01-phone-otp`).
- Claude opens PRs; **the user merges them** (Claude never merges its own PRs). After a merge, the next `/start-feature` switches to `main`, pulls, and deletes merged local branches.
- Commits and PRs: short, human-style, related changes in separate commits; no AI attribution. Flow: `/start-feature` → work → `/commit` → `/open-pr`.

## Layout

| Folder | What | Notes |
|---|---|---|
| `api/` | Laravel API | See `api/CLAUDE.md` |
| `apps/web/` | Next.js — public site, students, professor studio | See `apps/web/CLAUDE.md` |
| `apps/admin/` | Next.js — back office | See `apps/admin/CLAUDE.md` |
| `packages/` | Shared front-end packages (ui, api-client, i18n, config) | See `packages/CLAUDE.md` |
| `mobile/` | Flutter app (phase 3) | See `mobile/CLAUDE.md` |
| `docs/` | Architecture, PRDs, runbooks | |

## Claude Code setup (`.claude/`)

- **Commands:** `/start-feature <branch>`, `/implement <PRD-NN FR-NN>`, `/review`, `/commit`, `/open-pr`, `/decide <decision>`, `/docs-sync`, `/new-prd <area>`
- **Subagents:** `api-engineer`, `frontend-engineer`, `flutter-engineer`, `test-writer`, `reviewer` (read-only), `ledger-auditor` (read-only), `docs-keeper`
- **Skills:** `api-endpoint`, `schema-change`, `frontend-feature`, `flutter-feature`, `prd-author`
- **Rules** (load by path): money & ledger, database, i18n, generated code, content protection
- Commits: short, human-style subject line, no AI attribution (enforced in `.claude/settings.json`).

## Commands

- Local services: `docker compose up -d` (Postgres 55432, Valkey 6379, Meilisearch 7700)
- API: see `api/CLAUDE.md` → Commands (`composer setup`, `composer check`)
- Web apps and mobile: added when scaffolded (Phase 2 / 3)
