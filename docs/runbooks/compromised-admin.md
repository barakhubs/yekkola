# Runbook — Compromised admin account

**Symptoms:** unexpected admin actions in the audit log (refunds, adjustments, payout changes, settings), login from unusual location, staff member reports lost phone/SIM.

**Impact:** potential financial loss and data exposure. Treat as critical.

## Act immediately
1. Admin (another super admin) → Staff → deactivate the account; force sign-out (revokes sessions).
2. Pause payout batches (do not execute any pending batch).
3. If the phone/SIM was lost: also ban sign-in for that number until the person is re-verified.

## Check
4. Audit log: every action by that account since the suspected start — refunds, adjustments, payout method changes, settings, role changes, KYC views.
5. Payout methods changed recently (professors' numbers swapped is the classic fraud).
6. Settings changed (revenue split, gateways, limits).

## Recover
7. Reverse damage through audited admin actions: reversing ledger adjustments, restoring settings, restoring payout methods (with professor confirmation).
8. Rotate any secrets the account could see (if super admin): Mux keys, gateway keys, webhook secrets.
9. Re-enable the staff member only with a new verified number and TOTP enabled.

## Follow up
- Incident note; notify affected professors/students; if personal data was exposed, follow the data-protection notification duty (confirm with counsel).
- Consider mandatory TOTP for all staff and second-approver thresholds for finance actions.
