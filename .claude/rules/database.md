---
paths:
  - "api/database/**"
  - "api/app/Domain/**/Models/**"
---

# Database conventions

- PostgreSQL. Primary keys are ULIDs (`HasUlids`). Index every foreign key and every column used to filter or sort a list.
- Money: `*_minor` bigint + `currency` char(3) on the same row. Shares in basis points (`*_bps`).
- Enums: `varchar` columns backed by PHP enums (not Postgres enums).
- Admin-managed translatable text: `jsonb` `{fr, en}` via `spatie/laravel-translatable`.
- Provider payloads: `jsonb`. PII that must be stored (payout numbers, payer MSISDN) uses encrypted casts.
- Migrations are additive and reversible; never edit a migration that has run in staging/production — add a new one.
- Large tables (`lesson_progress`, `*_events`) — avoid patterns that prevent future partitioning (e.g. cross-table cascades on them).
- Any schema change must update `docs/architecture.md` §3 in the same change.
