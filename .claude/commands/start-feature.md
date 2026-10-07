---
description: Start a new branch from an up-to-date main (blocks if unmerged work exists)
argument-hint: <type/short-name, e.g. feature/prd-01-phone-otp>
allowed-tools: Bash(git status*), Bash(git branch*), Bash(git checkout*), Bash(git switch*), Bash(git pull*), Bash(git fetch*), Bash(git log*), Bash(gh pr list*)
---

Start branch: $ARGUMENTS

1. `git status` — if there are uncommitted changes, stop and ask what to do with them.
2. Check for unmerged work:
   - `git branch --no-merged main`
   - `gh pr list --author @me --state open` (skip if no remote/gh)
   If any unmerged branch or open PR exists, **stop**. Tell me which, and suggest finishing it (`/open-pr`, get it merged) before starting a new one. Only continue if I explicitly say so.
3. `git checkout main` then `git pull --ff-only origin main`. If the pull fails or there's no remote, stop and tell me.
4. Create the branch: `git switch -c <name>`. Name format: `feature/…`, `fix/…`, `chore/…`, `docs/…` — kebab-case, short; include the PRD ID when relevant. If $ARGUMENTS is empty or doesn't fit, propose a name and ask.
5. Confirm: current branch and the `main` commit it started from.
