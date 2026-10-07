# Runbook — Failed payouts

**Symptoms:** payout batch status `partially_failed`; professors report missing payouts.

**Impact:** professor trust; funds returned to available balance (no money lost).

## Check
1. Admin → Finance → Payout batches → the batch → failed lines and failure reasons.
2. Group reasons: invalid number / name mismatch (professor data) vs gateway errors (provider) vs insufficient float (our disbursement balance).
3. Ledger integrity job status (must be green).

## Act
- **Invalid number / name mismatch:** professor is notified automatically; they update the payout method (OTP + 48 h hold); line goes into the next batch.
- **Gateway errors:** retry failed lines from the batch screen after the provider confirms recovery.
- **Insufficient float:** top up the disbursement account, then retry.
- **Gateway says paid but we show failed (late success):** use recheck; the ledger posts the payout; never post manually.

## Recover
- Confirm every failed line is either paid or back in available balance.
- Integrity job green.

## Follow up
- Incident note; if float ran out, adjust top-up schedule.
