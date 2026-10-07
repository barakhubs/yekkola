---
description: Plan and implement a PRD requirement (e.g. /implement PRD-05 FR-03)
argument-hint: <PRD-NN> [FR-NN ...]
---

Implement: $ARGUMENTS

0. Make sure we're on a branch for this work. If on `main`, run the `/start-feature` steps first (pull main, check for unmerged branches/PRs, create `feature/<prd-id>-<short-name>`).
1. Read `project-context.md`, the PRD named above, and the matching sections of `docs/architecture.md`.
2. List the requirements in scope (IDs + one line each), their business rules, edge cases, and acceptance criteria. Flag anything that depends on an *Open decision* or a missing endpoint — stop and ask if it blocks the work.
3. Propose a short plan: API changes (schema, actions, endpoints, tests), then front-end/mobile changes. Wait for my go-ahead if the plan touches the data model or money flows.
4. Implement backend first (use the `api-engineer` approach / `api-endpoint` and `schema-change` skills), then UI (`frontend-feature` / `flutter-feature`).
5. Run tests, lint, and typecheck for every part touched.
6. Update `docs/architecture.md` if schema or endpoints changed.
7. Summarize: requirement IDs done, files changed, tests run and results, anything left open. Do not commit unless I ask.
