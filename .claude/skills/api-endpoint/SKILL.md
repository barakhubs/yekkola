---
name: api-endpoint
description: Add or change a Yekkola Laravel API endpoint end to end — route, form request, action, policy, API resource, Pest tests, OpenAPI/client regeneration, and docs. Use whenever work touches api/routes or api/app/Http/Controllers.
---

# Add or change an API endpoint

Follow these steps in order. Read `api/CLAUDE.md` and the relevant PRD first.

1. **Locate the requirement.** Find the PRD requirement (e.g. `PRD-05 FR-03`) and the endpoint in `docs/architecture.md` §4.2. If the endpoint isn't there, decide its audience group (`public`, `student`, `professor`, `admin`, `webhooks`) and add it to the catalogue.
2. **Route** in `api/routes/api_v1.php` under the right group and middleware (auth, `EnsureAccountActive`, role/permission, throttle, `IdempotencyKey` for money).
3. **Form Request** in `app/Http/Requests/<Audience>/` — all validation here; translated messages.
4. **Action** in `app/Domain/<Domain>/Actions/` — the business logic. `final` class, one public `handle()` (or `__invoke`), dependencies via constructor (interfaces for integrations), typed inputs (`Money`, `PhoneNumber` value objects), returns a model/DTO. Cross-domain effects → dispatch a domain event. See *Code design* in `api/CLAUDE.md`.
5. **Policy** for authorization (`$this->authorize()` / `Gate`). Never authorize by which client is calling.
6. **Controller** in `app/Http/Controllers/Api/V1/<Audience>/` — thin: request → action → resource.
7. **API Resource** in `app/Http/Resources/` — stable shape, ISO-8601 dates, money as `{amount_minor, currency}`, no hidden fields leaked (e.g. quiz `is_correct`).
8. **Lists:** use `spatie/laravel-query-builder` with explicit allowed filters/sorts/includes; paginate.
9. **Errors:** throw domain exceptions that render the standard error envelope with a stable `code`.
10. **Tests (Pest)** in `tests/Feature/Api/V1/<Audience>/`: unauthenticated, unauthorized, validation, happy path, and each business rule/edge case from the PRD. Use fake drivers for Mux, payments, SMS.
11. **Contract:** regenerate the OpenAPI spec and `packages/api-client`; commit both with the change.
12. **Docs:** update `docs/architecture.md` §4.2 (and §3 if schema changed).

Done when: tests pass, PHPStan clean, Pint formatted, client regenerated, docs updated.
