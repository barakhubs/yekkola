# Runbooks

What to do when something breaks in production. Each runbook: **symptoms → impact → check → act → recover → follow up**. Keep them short and update them after every incident.

| Runbook | Trigger |
|---|---|
| [Stuck payments](stuck-payments.md) | Payments pending past timeout, students paid but no access |
| [Failed payouts](failed-payouts.md) | Payout batch partially/fully failed |
| [Mux outage](mux-outage.md) | Playback, uploads, or webhooks failing |
| [SMS outage](sms-outage.md) | OTP codes not arriving |
| [Compromised admin account](compromised-admin.md) | Suspicious admin activity |

General rules:
- Post status in the team channel first; note start time.
- Never edit ledger rows or payment rows by hand — use admin actions (recheck, adjustment) so everything is audited.
- After the incident: write a short note (what, why, fix, prevention) and update the runbook.
