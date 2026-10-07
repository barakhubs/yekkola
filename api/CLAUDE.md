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

Notes: Laravel's test client sends `Accept-Language: en-us` by default — set the header explicitly in locale-sensitive tests.

## Building blocks

| Need | Use |
|---|---|
| Money / phone numbers | `App\Domain\Shared\ValueObjects\{Money, PhoneNumber}` |
| Business-rule error | throw a subclass of `App\Domain\Shared\Exceptions\DomainException` (renders as the error envelope; not reported) |
| Retry-safe endpoint | middleware alias `idempotent` |
| Platform settings | inject `App\Domain\Platform\Settings\*Settings` (Commerce, Payout, Protection, Catalog, Learning, Moderation). Add new keys with a settings migration in `database/settings/`. Changes are audited automatically. |
| Audit an admin action | `App\Domain\Platform\Actions\RecordAudit::handle('thing.happened', $subject, ['reason' => ...])` |
| Signed-in routes | `auth:sanctum` + `active` middleware (ends stale web sessions, refuses banned, limits suspended users to `me.show`/`auth.logout`) |
| End all sessions/tokens | `App\Domain\Identity\Actions\SignOutEverywhere` (bumps `auth_epoch`, deletes tokens) |
| Phone of a user | `$user->phone` (a `PhoneNumber`); `phone_e164` is only the column |
| Roles / permissions | `App\Domain\Identity\Enums\{Role, Permission}` — policies check permissions, never role names. Re-run `RolesAndPermissionsSeeder` after changing the enums. |
| Video/audio host | `App\Integrations\Video\VideoProvider` (fake driver now; Mux in 1.5) |
| Mobile money | `App\Integrations\Payments\PaymentGateway` (fake driver until the aggregator is chosen) |
| SMS | `App\Integrations\Sms\SmsSender` (`log` driver until the provider is chosen) |

Drivers are set in `config/yekkola.php` (`VIDEO_DRIVER`, `PAYMENT_DRIVER`, `SMS_DRIVER`); production refuses to boot with `fake`/`log`. New real drivers must pass the contract tests in `tests/Feature/Integrations/*ContractTest.php` (add them to the dataset).

**Payment rules:** look transactions up by **our** reference; a start that throws `GatewayOutcomeUnknown` stays pending and is reconciled, never retried under a new reference; `UnknownTransaction` means "keep pending", never "failed"; always compare the confirmed `amount` with what was requested; amounts must be multiples of `Currency::collectionStepMinor()` (CDF = whole francs).

**FakeGateway outcomes** by the last 4 digits of the payer/recipient number (full list in `Scenario`): `0001` insufficient funds, `0002` pending forever, `0003` late success, `0004` reversal, `0005` fail then succeed, `0006` unavailable, `0007` timeout-but-succeeds, `0008` amount mismatch, `0009` rejected by payer, `0010` invalid recipient; anything else pending → succeeded on re-check. Tests can force outcomes with `queue(Scenario::...)` and outages with `makeUnavailable($rail)`; `webhookFor($reference)` builds a signed webhook.

**Auth tests:** helpers in `tests/Pest.php` — `signInMobile()`, `signInWeb()`, `lastSmsCode($e164)`, `bearer($token)`, `WEB_APP` headers, `freshAuth()` (call between requests: the test client reuses cached guards and models).

**LogSmsSender:** numbers ending `0000` simulate a provider rejection. Logs mask the number.

Fake drivers outside local/testing need `FAKE_WEBHOOK_SECRET`. Set `DEMO_ADMIN_PHONE` (a number you control) to seed a demo super admin locally/on staging.
