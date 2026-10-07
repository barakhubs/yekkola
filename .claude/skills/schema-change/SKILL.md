---
name: schema-change
description: Create or change Yekkola database tables, models, and enums following project conventions (ULIDs, money columns, jsonb translations, indexes) and keep docs/architecture.md §3 in sync. Use for any migration, model, or enum work in api/.
---

# Schema change

1. **Check the design.** Find the table in `docs/architecture.md` §3. If you're deviating or adding a table, update §3 first so the doc stays the source of truth.
2. **Migration** (`php artisan make:migration`): ULID `id`, `foreignUlid()->constrained()` with explicit `onDelete` behaviour, indexes on filter/sort columns, unique constraints for business uniqueness (e.g. `(user_id, course_id)` on enrollments).
3. **Money:** `bigInteger('<name>_minor')` + `char('currency', 3)`. Shares: `integer('<name>_bps')`.
4. **Enums:** `string` column + PHP backed enum in `app/Domain/<Domain>/Enums/`, cast on the model.
5. **Model** in `app/Domain/<Domain>/Models/`: `HasUlids`, casts (enums, `encrypted` for PII, `array`/`AsArrayObject` for jsonb), relations, no business logic beyond simple accessors/scopes.
6. **Factory + seeder** for every new model (seed realistic DRC data: +243 numbers, all provinces, fr/en text — never default everything to one city).
7. **Tests:** model factory works; constraints enforced (unique, foreign keys).
8. **Never** edit a migration already deployed to staging/production — add a new migration.

Done when: migration up/down works, factories seed, `docs/architecture.md` §3 matches.
