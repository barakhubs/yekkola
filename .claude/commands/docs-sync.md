---
description: Check docs against the code and fix drift (schema, endpoints, structure)
---

Use the `docs-keeper` subagent to compare the docs with the code:

- `docs/architecture.md` §3 vs `api/database/migrations` and models
- `docs/architecture.md` §4.2 vs `api/routes` (and `php artisan route:list` if available)
- `docs/architecture.md` §2 vs the actual folder structure
- CLAUDE.md "Commands" sections vs real scripts in `composer.json`, `package.json`, `pubspec.yaml`

List the drift found, fix the docs (not the code), and report what changed. If the code looks wrong rather than the docs, flag it instead of editing.
