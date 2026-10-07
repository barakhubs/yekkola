# Yekkola API

Laravel 13 JSON API (`/api/v1`) for the Yekkola web app, back office, and mobile app. Conventions: [`CLAUDE.md`](CLAUDE.md). Design: [`../docs/architecture.md`](../docs/architecture.md).

## Local setup

```bash
# from the repo root: Postgres (55432), Valkey (6379), Meilisearch (7700)
docker compose up -d

cd api
composer setup        # install, .env, key, migrate
composer check        # Pint + PHPStan + Pest
php artisan serve     # http://localhost:8000/api/v1/ping
```

On Windows, Horizon's `pcntl`/`posix` extensions don't exist; `composer setup` already passes `--ignore-platform-req` for them. Horizon itself runs on Linux (Laravel Cloud, CI).
