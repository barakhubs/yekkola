# Runbook — Mux outage

**Symptoms:** playback errors spike (Sentry `playback_error`), uploads stuck in `processing`, Mux webhook lag.

**Impact:** streaming and new downloads fail. **Already-downloaded lessons keep playing offline** (persistent licenses) — say so in comms.

## Check
1. status.mux.com.
2. Sentry: errors by client and region; are token requests (our API) healthy?
3. `webhook_events` processing lag; media assets stuck in `processing` count.

## Act
- **Mux down:** post an in-app banner (FR/EN): streaming temporarily unavailable, downloaded lessons still work. No code change needed.
- **Our token endpoint failing (Mux up):** check signing keys/env vars, recent deploys; roll back if needed.
- **Webhooks delayed:** they are retried by Mux; our handler is idempotent. After recovery, run the "resync processing assets" job to re-query asset status.

## Recover
- Remove banner; confirm playback error rate back to baseline; no assets left in `processing` > 1 h.

## Follow up
- Incident note; consider status-page subscription alerts.
