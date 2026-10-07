---
description: Start a new branch from an up-to-date main (finishes any open branch first)
argument-hint: <type/short-name, e.g. feature/prd-01-phone-otp>
---

Start branch: $ARGUMENTS

Run fully automatically — don't ask the user to do anything.

1. `git status`. If there are uncommitted changes on a work branch, commit them there (grouped, short messages) and run `/open-pr` on that branch first. If they're on `main`, carry them onto the new branch.
2. If an unmerged branch has unpushed or uncommitted work, run `/open-pr` on it. If an open PR is still waiting to be merged, stop and report it (the user merges PRs) — do not create another branch.
3. `git checkout main` → `git pull --ff-only origin main` → delete local branches already merged into `main`.
4. Create the branch: `git switch -c <name>` — `feature/…`, `fix/…`, `chore/…`, `docs/…`, kebab-case, PRD ID when relevant. If $ARGUMENTS is empty, choose the name from the task.
5. Update `TODO.md`: mark the task(s) `[~]`, set *Now → Current branch*, bump *Last updated*; add the task under the right phase if missing.
