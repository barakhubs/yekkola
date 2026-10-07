---
description: Push the current branch and open a pull request into main
argument-hint: "[optional title hint]"
allowed-tools: Bash(git status*), Bash(git diff*), Bash(git log*), Bash(git branch*), Bash(gh pr*)
---

Open a PR for the current branch. Title hint: $ARGUMENTS

1. Refuse if the current branch is `main`.
2. `git status` — if there are uncommitted changes, ask whether to commit them first (`/commit`).
3. Make sure `TODO.md` reflects this branch's work (items ticked `[x]`, *Now* and *Last updated* current); fix and commit if not. After the PR is created, add its number to the ticked items in a follow-up commit on the same branch.
4. Run the `/review` checks on `git diff main...HEAD`; if there are blockers, list them and stop.
5. Push: `git push -u origin <branch>` (this will ask for permission).
6. `gh pr create --base main`:
   - Title: short, human-style (≤ 60 chars).
   - Body: 2–5 short bullets of what changed + PRD requirement IDs + how it was tested. No AI attribution, no "Generated with" footer.
7. Show the PR URL. Remind me that a new branch should wait until this PR is merged.
