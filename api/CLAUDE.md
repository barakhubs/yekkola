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

## Code design (SOLID, pragmatically)

- **Single responsibility:** one Action per business operation (`CreateOrder`, `IssuePlaybackToken`). Controllers only do request → action → resource. Validation in Form Requests, authorization in Policies, shaping in Resources.
- **Open/closed:** extend by adding, not editing — new provider = new driver class; new reaction to a domain event = new listener.
- **Liskov:** fake drivers (`FakeGateway`, `LogSmsSender`, `FakeVideoProvider`) honour the same contract as real ones, including failures, timeouts, and reversals. Contract tests run against both.
- **Interface segregation:** small, focused interfaces (`VideoProvider`, `PaymentGateway`, `SmsSender`) — no catch-all service interfaces.
- **Dependency inversion:** domain code depends on interfaces; implementations are bound in service providers and injected via the constructor.

Guardrails — don't over-engineer:
- **No repository layer over Eloquent.** Actions use models directly; reusable queries go in model scopes or dedicated query classes.
- **Interfaces only where there's a real swap or test fake** (external services, possibly the ledger). Don't create an interface per class.
- **Constructor injection** for services; no `new` on services inside actions. Facades only for framework concerns (Log, Cache, Queue, DB).
- **Value objects** for core concepts: `Money` (amount_minor + currency, arithmetic refuses mixed currencies) and `PhoneNumber` (E.164, masking). No loose ints/strings for these across boundaries.
- **Composition over inheritance:** no deep base classes for actions or controllers; small traits only when genuinely shared.
- Actions are `final`, have one public method, and are unit-testable without HTTP.

## Commands

Run PHP commands from PowerShell on Windows (Herd's PHP); Bash may pick up a different PHP without `pgsql`.

| Task | Command |
|---|---|
| Start local services (repo root) | `docker compose up -d` — Postgres on **55432**, Valkey 6379, Meilisearch 7700 |
| First setup | `composer setup` |
| Serve | `php artisan serve` → `http://localhost:8000/api/v1/ping` |
| All checks (CI equivalent) | `composer check` |
| Tests | `composer test` (Pest, Postgres `yekkola_test`) |
| Style | `composer format` (fix) / `composer lint` (check) |
| Static analysis | `composer analyse` (PHPStan level 6 + Larastan) |
| OpenAPI spec | `composer openapi` → `api/openapi.json` (commit it; CI fails if stale) |
| Queue dashboard | `php artisan horizon` (Linux only — needs `pcntl`) |
| Installing packages on Windows | add `--ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix` |

Notes: Laravel's test client sends `Accept-Language: en-us` by default — set the header explicitly in locale-sensitive tests. Shared building blocks: `App\Domain\Shared\ValueObjects\{Money, PhoneNumber}`, `App\Domain\Shared\Exceptions\DomainException` (renders as the error envelope), middleware alias `idempotent`.
