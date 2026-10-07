---
name: flutter-feature
description: Build a feature in the Yekkola Flutter app (mobile/) following the offline-first architecture — drift as source of truth, outbox sync, Riverpod, generated API client, Mux plugin for media. Use for any work under mobile/.
---

# Build a mobile feature

Read `mobile/CLAUDE.md`, PRD-06 and PRD-07 first.

1. **Layers:** `lib/features/<feature>/{data,domain,presentation}`. Presentation never calls the API directly.
2. **Offline-first:** UI reads from drift; repositories refresh drift from the API. Every screen defines its offline state.
3. **Writes** (progress, quiz attempts, Q&A posts) go to the outbox table first, then the sync engine flushes them with backoff. Server merges are monotonic — don't fight them on the client.
4. **Media:** only through `packages/mux_player_plugin` (download, progress, delete, play with watermark overlay). Show size before download; respect the Wi-Fi-only setting.
5. **Entitlements:** after every sync, delete content the API reports as revoked.
6. **Auth:** tokens in flutter_secure_storage; always send `X-Device-Id`; handle `device.limit_reached` with the device-management flow.
7. **i18n:** ARB strings in fr + en; shared formatters for money/dates.
8. **Performance:** test on a low-end Android profile (2 GB RAM); avoid heavy rebuilds and large images.
9. **Tests:** unit tests for repositories/sync logic; widget tests for key screens.
