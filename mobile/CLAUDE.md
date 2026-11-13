# mobile/ — Flutter app (phase 3)

Student app. Download-then-watch is the primary pattern — never design streaming-only. Structure: `docs/architecture.md` §6; requirements: PRD-06 and PRD-07.

## Conventions

- Features under `lib/features/<feature>/{data,domain,presentation}`; shared code in `lib/core/`.
- Riverpod for state, Dio client generated from the OpenAPI spec, drift (SQLite) as the source of truth for the UI.
- Offline-first: writes (progress, quiz attempts) go to an outbox and sync when online; entitlement sync deletes revoked downloads.
- Video/audio: encrypted (AES-128) HLS from R2, played with a standard Flutter player and downloaded with our own downloader; the key is wrapped with a per-device key and expires with the offline license setting. Never store plain media files or plain keys on device.
- Tokens in flutter_secure_storage; send `X-Device-Id` on every request.
- French (default) + English via ARB. Brand: Nunito bundled as an asset font; colours from the shared tokens (indigo `#2c3892`, amber `#fdb73b`). Spec: `docs/brand.md`.
- Must run well on low-end Android (2 GB RAM, Android 8+).

## Layout

- `lib/core/`: config (flavor → API), `api/` (Dio, headers interceptor, `ApiException`, generated client), storage, device identity, i18n (locale, formatters), theme, router.
- `lib/core/api/generated/` and `lib/l10n/generated/`: generated, never edited by hand.
- `lib/core/cache/`: `ResponseCache` (offline copies of read-only answers, stale-while-revalidate).
- `lib/features/<feature>/{data,domain,presentation}`: `auth` (sign-in, session), `account` (devices), `home` (tabs shell, account menu), `catalog` (explore, search, course and professor pages), `learning` (my courses, course view, streaming, progress, documents, quizzes), `media` (offline license, segment downloader, key vault, loopback server, offline player, staging-only lab screen).
- `lib/core/db/` (drift: outbox, lesson progress, sync state) and `lib/core/sync/` (outbox store, `SyncEngine`, `SyncScheduler`, providers).
- `lib/core/platform/`: platform channels (`SecureScreen` → `FLAG_SECURE` in `MainActivity.kt`).

## Commands (run in `mobile/`)

- Run: `flutter run --flavor staging` (or `production`). Local API from the emulator: add `--dart-define=API_BASE_URL=http://10.0.2.2:8000/api/v1`.
- Checks: `flutter analyze`, `flutter test` (if drive C: is short on space, set `TEMP`/`TMP` to a folder on another drive, e.g. `mobile/build/tmp`), `dart format lib test tool` (page width 120; generated code, including `*.g.dart`, is left alone).
- API client: `dart run tool/generate_api_client.dart` after `composer openapi` in `api/`. It cuts the spec to student-facing operations (no admin/professor/staff/webhooks) and runs swagger_parser (plain Dio + plain codecs, no build_runner). `--check` fails if stale (CI).
- Database (drift, `lib/core/db/app_database.dart`): after changing tables run `dart run build_runner build` and commit `app_database.g.dart` (CI checks it); bump `schemaVersion` with a migration.
- Money and dates only through `core/i18n/formatters.dart` (`money` matches the web's `formatMoney`).
- Translations: edit `lib/l10n/app_fr.arb` (template, French first) and `app_en.arb`; `flutter pub get` regenerates.
- Build: `flutter build apk --flavor staging`. Flavors exist on Android only so far; iOS schemes are a TODO (needs a Mac).
