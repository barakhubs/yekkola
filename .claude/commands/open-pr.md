---
description: Prepare and open the PR for the current branch — commit, update TODO, review and fix, test, push, open PR, pass CI
argument-hint: "[optional PR title hint]"
---

Open a PR for the current branch. Title hint: $ARGUMENTS

Run automatically — don't ask the user to do anything except merge the PR at the end. Only stop for a genuine blocker you cannot fix (then report it clearly).

1. Refuse if on `main`.
2. Commit any uncommitted changes in related groups (short, human-style messages, no AI attribution).
3. `TODO.md`: tick this branch's items `[x]`, update *Now* and *Last updated*. Commit if changed.
4. Review `git diff main...HEAD`:
   - Code changes → `reviewer` subagent; Commerce/Finance/Payments changes → also `ledger-auditor`.
   - Docs/config only → quick self-check (valid JSON/frontmatter, no secrets, links).
   - Fix blockers and majors yourself, commit, re-review. Minor items: fix or list in the PR body.
5. Run the relevant tests/lint/typecheck; fix failures.
6. `git push -u origin <branch>`.
7. `gh pr create --base main` — short title (≤ 60 chars); body: 2–5 bullets of what changed, PRD IDs, how it was tested, review notes. No AI attribution.
8. Add the PR number to the ticked `TODO.md` items (`— #N`), commit, push.
9. If CI is configured: `gh pr checks <N> --watch`; fix failures and push until green.
10. Report the PR URL and that it's ready for the user to merge. Don't start another branch until it's merged; the next `/start-feature` will sync `main` and clean up.
