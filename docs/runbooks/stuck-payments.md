# Runbook — Stuck payments

**Symptoms:** alert "pending payments > threshold"; students report "I paid but have no access"; payment success rate drops for one rail.

**Impact:** students charged without access (trust), or lost sales.

## Check
1. Admin → Commerce → Payments → filter `pending`, sort by age. Is it one rail or all?
2. Admin → Settings → Payment gateways: is the rail enabled?
3. Horizon: `payments` queue depth and failed jobs; webhook processing lag.
4. Gateway status page / aggregator support channel.
5. For a specific student: find the order by phone or order number → timeline (attempts, gateway events, ledger).

## Act
- **Single payment:** use **Recheck with gateway** on the payment. If the gateway says success, the normal handler grants access and posts the ledger.
- **One rail failing:** pause that rail in settings (checkout hides it with a message).
- **Webhooks not arriving:** confirm the reconciliation job is running (it re-queries pending payments every 5 min); run it manually from the scheduler if needed.
- **Queue backlog:** scale `payments` workers in Laravel Cloud.
- **Student paid, gateway confirms, still no access:** check failed jobs for the success handler; retry it. Never create an enrollment manually for a paid order — fix and retry the handler.

## Recover
- Re-enable the rail when the gateway confirms recovery.
- Review payments that succeeded after order expiry (flagged) — confirm access was granted.

## Follow up
- Incident note; check alert thresholds; contact affected students (in-app + SMS receipt).
