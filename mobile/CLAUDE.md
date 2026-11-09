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

## Commands

Not scaffolded yet.
