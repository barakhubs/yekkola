---
description: Commit current changes with a short, human-style message
argument-hint: "[optional message hint]"
allowed-tools: Bash(git status*), Bash(git diff*), Bash(git add*), Bash(git commit*), Bash(git log*)
---

Commit the current changes. Hint: $ARGUMENTS

0. If the current branch is `main`, stop: work must be on a branch. Suggest `/start-feature <name>` (the uncommitted changes carry over to the new branch).
1. Run `git status` and `git diff` to see what changed. Don't commit secrets, `.env` files, or unrelated changes — ask if unsure.
2. Write a short, human-style message: one imperative subject line, ≤ 60 characters, no prefix jargon unless the repo already uses it (e.g. "Add phone OTP sign-in", "Fix payment webhook dedupe").
3. No body unless genuinely needed. **Never** add "Co-Authored-By", "Generated with Claude", or any AI attribution.
4. Stage the relevant files and commit. Show the resulting `git log --oneline -1`.
