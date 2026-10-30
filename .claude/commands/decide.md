---
description: Record a product or technical decision and update every doc it affects
argument-hint: <the decision, e.g. "default revenue split is 70/30">
---

Decision: $ARGUMENTS

Use the `docs-keeper` subagent to:

1. Find every place in `project-context.md`, `docs/architecture.md`, `docs/prd/`, and the CLAUDE.md files that this decision affects (including _Open decisions_ and _Platform settings_).
2. Update them consistently. If the decision resolves an open item, remove it from _Open decisions_. If the value is admin-controlled, record it as the default in _Platform settings_ rather than hard-coding it.
3. Tick the matching item in `TODO.md` (Phase 0.5) with a short note of the outcome, and unblock any tasks that were waiting on it.
4. Report every file changed with a one-line summary each.

Commit the doc updates (short message) on the current branch, or start a `docs/` branch via `/start-feature` if on main, then `/open-pr`.
