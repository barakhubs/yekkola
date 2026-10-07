# Runbook — SMS outage (OTP not arriving)

**Symptoms:** OTP verify rate drops; delivery-report failures rise; support messages "code never came".

**Impact:** new sign-ins blocked. **Existing sessions/tokens keep working.**

## Check
1. Delivery reports by operator (Orange / Airtel / Vodacom / Africell) — one operator or all?
2. SMS provider status page and account balance/credit.
3. OTP request rate — is this an abuse spike (SMS pumping) eating credit?

## Act
- **Out of credit:** top up.
- **Abuse spike:** tighten per-IP limits, enable stricter bot challenge, block offending IP ranges/prefixes.
- **Provider down for one operator / all:** in-app/web banner (FR/EN) asking users to retry later; if a secondary provider is integrated, switch the `SmsSender` driver via env and redeploy.

## Recover
- Confirm delivery rate back to normal; remove banner.

## Follow up
- Incident note; consider a second SMS provider for failover.
