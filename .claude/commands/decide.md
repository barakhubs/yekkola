---
description: Record a product or technical decision and update every doc it affects
argument-hint: <the decision, e.g. "default revenue split is 70/30">
---

Decision: $ARGUMENTS

Use the `docs-keeper` subagent to:
1. Find every place in `project-context.md`, `docs/architecture.md`, `docs/prd/`, and the CLAUDE.md files that this decision affects (including *Open decisions* and *Platform settings*).
2. Update them consistently. If the decision resolves an open item, remove it from *Open decisions*. If the value is admin-controlled, record it as the default in *Platform settings* rather than hard-coding it.
3. Tick the matching item in `TODO.md` (Phase 0.5) with a short note of the outcome, and unblock any tasks that were waiting on it.
4. Report every file changed with a one-line summary each.

Do not commit unless I ask.
