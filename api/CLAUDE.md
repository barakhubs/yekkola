# api/ — Laravel API

Pure JSON API (`/api/v1`). Serves no UI — no Blade views, Inertia, or Filament (Horizon dashboard is the only exception, for engineers).

## Conventions

- Code by domain under `app/Domain/<Domain>/` (Actions, Models, Events, Policies, Enums). Domains listed in `docs/architecture.md` §1.2.
- Controllers in `app/Http/Controllers/Api/V1/{Auth,Public,Student,Professor,Admin,Webhooks}` stay thin: validate (Form Request) → call an Action → return an API Resource.
- Cross-domain side effects go through domain events + queued listeners, not direct calls from controllers.
- Integrations in `app/Integrations/{Video,Payments,Sms}` behind interfaces, each with a fake driver.
- PostgreSQL, ULID primary keys. Money = `*_minor` bigint + `currency`. Revenue shares in basis points.
- Lists support server-side filter/sort/pagination (`spatie/laravel-query-builder`).
- Errors use the RFC 9457-style envelope with a stable `code` (see architecture §4.1). Messages translated via `Accept-Language` (`lang/fr`, `lang/en`).
- Money and webhooks: idempotency keys and event-ID deduplication. Ledger rows are never updated or deleted.
- Every endpoint gets Pest feature tests (auth, validation, authorization, happy path).

## Commands

Not scaffolded yet. Add once Laravel is installed (serve, test, Pint, PHPStan, OpenAPI export, Horizon).
