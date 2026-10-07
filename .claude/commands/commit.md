---
description: Commit current changes in related groups with short, human-style messages
argument-hint: "[optional message hint]"
---

Commit the current changes. Hint: $ARGUMENTS

Run automatically — don't ask the user.

0. If on `main`, create a branch first with `/start-feature` (changes carry over).
1. `git status` / `git diff`. Never commit secrets or `.env` files (unstage and leave them out).
2. Group related changes into separate commits (e.g. schema, then API, then docs).
3. Each message: one imperative subject line, ≤ 60 characters, human-style (e.g. "Add phone OTP sign-in"). No body unless genuinely needed. **Never** add "Co-Authored-By", "Generated with Claude", or any AI attribution.
4. Show `git log --oneline main..HEAD`.
