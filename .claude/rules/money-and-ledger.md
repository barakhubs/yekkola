---
paths:
  - "api/app/Domain/Commerce/**"
  - "api/app/Domain/Finance/**"
  - "api/app/Integrations/Payments/**"
  - "api/tests/**/Commerce/**"
  - "api/tests/**/Finance/**"
---

# Money & ledger rules (non-negotiable)

- Amounts are integers in minor units (`*_minor`) and always travel with a `currency`. No floats, no implicit currency, no conversion.
- Revenue split and prices are read from the **order item snapshot**, never recomputed from current course/professor settings.
- Ledger is double-entry and append-only: every posting is a `LedgerTransaction` whose debits equal credits. Never update or delete ledger rows — post a reversing or adjusting transaction.
- Payment success is confirmed by re-querying the gateway, never by a webhook body or client redirect alone.
- Webhook and payment handlers must be idempotent: dedupe by gateway event ID, safe to run twice, wrapped in a DB transaction.
- Order creation and payment start require an `Idempotency-Key`.
- Enrollments from purchases are created only by the payment-success handler.
- Every new money path needs a test proving the transaction balances and that a duplicate event changes nothing.
- See `docs/prd/05-checkout-payments.md` and `docs/prd/08-earnings-payouts.md`.
