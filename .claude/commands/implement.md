---
description: Implement a PRD requirement end to end and open its PR (e.g. /implement PRD-05 FR-03)
argument-hint: <PRD-NN> [FR-NN ...]
---

Implement: $ARGUMENTS

Run fully automatically — don't ask the user to do anything.

1. `/start-feature feature/<prd-id>-<short-name>` (checks for open work, pulls main, updates `TODO.md`).
2. Read `project-context.md`, the PRD, and the matching sections of `docs/architecture.md`. List the requirements in scope, business rules, edge cases, and acceptance criteria.
3. If something depends on an *Open decision*: don't block. Implement it behind a platform setting or interface with a safe default, document the default in the PRD/project-context, and add a `TODO.md` item to confirm it.
4. Implement backend first (`api-engineer` approach; `api-endpoint`, `schema-change` skills), then UI (`frontend-feature` / `flutter-feature`).
5. Data model or money changes: run the `reviewer` and `ledger-auditor` subagents and fix their findings before opening the PR.
6. Run tests, lint, typecheck for every part touched; fix failures.
7. Update `docs/architecture.md` (schema/endpoints) and `TODO.md` (tick done items, add discovered work).
8. Commit in related groups, then `/open-pr`.
9. Report: requirement IDs done, PR URL, test results, any defaults chosen for open decisions.
