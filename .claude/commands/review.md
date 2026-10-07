---
description: Review the current changes against PRDs, architecture, and project rules
argument-hint: "[branch or path — defaults to uncommitted changes]"
---

Review target: $ARGUMENTS (if empty, review the current uncommitted changes).

Use the `reviewer` subagent on the diff. If the diff touches `api/app/Domain/Commerce`, `api/app/Domain/Finance`, or `api/app/Integrations/Payments`, also run the `ledger-auditor` subagent in parallel.

Then give me one combined, ranked list of findings (blocker / major / minor) with file:line and the fix, followed by a one-line verdict: ready to commit or not.
