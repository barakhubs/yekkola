# Yekkola — Project Context

> Master brief for everyone (humans and AI agents) working on Yekkola. Read this first.
> Detail lives in the linked docs — this file states *what* and *why*; they state *how*.

## Docs map

| Doc | What it holds |
|---|---|
| `project-context.md` (this file) | Product, principles, stack, decisions, scope |
| [`docs/architecture.md`](docs/architecture.md) | System architecture, repo/file structure, database schema, API catalogue, UI architecture, scaling, security |
| [`docs/prd/`](docs/prd/) | One PRD per product area — requirements, rules, acceptance criteria |

When docs disagree, **this file wins**; fix the other doc in the same PR.

## What this is

Yekkola is a national online learning marketplace for the DRC: professors and instructors from anywhere in the country publish courses, and students anywhere in the country buy and consume them on web and mobile.

**Positioning:** Not generic e-learning. The core value prop is "learn from Congo's best teachers, wherever you are" — closing the gap between where expertise is and where students are. This should inform naming, copy, and curation decisions throughout the codebase (professor profile prominence, vetting badges, quality over volume).

**Not tied to one city.** Do not hard-code or privilege Kinshasa (or any city/province) in copy, onboarding, defaults, or data models. Location (province/city) is an optional attribute on professors and students — useful for filtering and analytics, never a requirement or a brand anchor.

**This is a full product, not a pilot.** Build features to production quality. Delivery is sequenced (see *Build sequence*), but scope is not cut — do not treat anything below as "later" unless it is listed under *Out of scope*.

## Glossary

Use these terms consistently in code, API, and UI copy.

| Term (code) | FR (UI) | Meaning |
|---|---|---|
| `Professor` | Professeur | Approved content creator. A `User` with an approved `ProfessorProfile`. |
| `Student` | Étudiant | Any user who enrolls in courses. Every user can be a student. |
| `Course` | Cours | Sellable unit. Has a price (or is free) and versions. |
| `CourseVersion` | — | Immutable-once-approved snapshot of a course's content. Students see the latest approved version. |
| `Lesson` | Leçon | Video, audio, document, or quiz inside a section. Has a stable `uid` across versions. |
| `Enrollment` | Inscription | The access grant. Access checks look **only** at active enrollments. |
| `Order` / `Payment` | Commande / Paiement | What the student buys / each attempt to pay for it. |
| `LedgerEntry` | — | Double-entry money record. Balances are derived from it. |
| `Payout` | Versement | Money sent to a professor's mobile-money account. |
| `Rail` | Opérateur | Orange Money, Airtel Money, M-Pesa. |

## Personas

- **Professor (supply side):** Based anywhere in the DRC, monetizing expertise. Needs low-friction upload (video, audio, documents, quizzes), pricing control, sales visibility, reliable payouts, and confidence that their content won't be pirated.
- **Student (demand side):** Anywhere in the DRC — big cities and remote areas alike; secondary / university / exam-prep motivated (incl. Examen d'État), budget-constrained, mobile-money-native, mobile-first, on expensive and unreliable data, often on low-end Android phones.
- **Admin / moderator:** Vets professors, approves content, handles disputes, refunds, payouts, and takedowns. Moderators have a subset of admin permissions (no finance, no settings).

## Languages

- UI: **French (default) and English.** All user-facing strings go through translation files from day one — no hard-coded copy in Laravel, React, or Flutter. API error and validation messages are translated via `Accept-Language`.
- Admin-managed text (categories, settings labels) is stored translated (`fr` + `en`).
- Content: each course is tagged with its language (`fr` / `en`). Course content itself is not translated.

## Brand

| Role | Hex | Use |
|---|---|---|
| **Primary** | `#2c3892` (deep indigo) | Main buttons, links, active nav, headers, focus rings, primary chart series |
| **Secondary** | `#fdb73b` (amber) | Highlights and accents: "Gratuit" badges, ratings stars, featured tags, promo banners, progress/celebration moments, second chart series |

**Contrast rules (WCAG AA):**
- White text on primary: ~10:1 ✓ — default for primary buttons.
- Primary text on secondary: ~5.7:1 ✓ — use indigo (or near-black) text on amber surfaces.
- Secondary text on primary: ~5.7:1 ✓ — amber accents on indigo backgrounds are fine.
- **White text on secondary: ~1.8:1 ✗** and **secondary text on white ✗** — never use amber for text or as a background behind white text.
- Dark mode: primary is too dark on dark backgrounds — use a lighter indigo tint for interactive elements, verified ≥ 4.5:1 against the dark surface.

**Tokens:** defined once in `packages/ui` (CSS variables, light + dark) and mirrored in the Flutter theme. Map shadcn `--primary` to brand indigo (`--primary-foreground` white). Keep shadcn's `--secondary` as a neutral (it's used for low-emphasis buttons everywhere) and expose amber as a separate `--brand-accent` / `--brand-accent-foreground` (indigo) token, so amber stays a highlight rather than flooding the UI. Logos, wordmark, and fonts: to be added to `packages/ui` when ready.

## Content model

`Course → CourseVersion → Sections → Lessons`, where each lesson is one of:

- **Video** — the expensive one: transcoding, DRM, bandwidth. Hosted on Mux.
- **Audio** — lecture-style content at a fraction of video's data cost. Hosted on Mux as audio-only assets. Treat as first-class, not an afterthought.
- **Document** — PDF/slides/worksheets. Stored in object storage.
- **Quiz** — multiple-choice/true-false practice tests with scoring and explanations. Central to the exam-prep persona.

**Versioning:**
- A course always has at most one *draft* version (being edited) and one *live* version (latest approved).
- Professors edit the draft; submitting sends it to review; approval makes it the live version. The previous live version is kept (superseded), never mutated.
- Students always see the live version. Lessons carry a stable `uid` across versions, so progress, quiz history, and Q&A threads survive edits.
- Course status: `draft → in_review → published → unpublished / archived`. A published course can have a draft in review at the same time without going offline.

## Content protection

Paid content must not be trivially shareable — piracy directly undercuts professor income. Protection applies to free courses too (it is the professor's content).

- **Video & audio:** Mux DRM (Widevine / FairPlay). Signed playback + DRM tokens are issued by the API only to users with an active enrollment (or for preview lessons).
- **Watermarking, two layers:**
  - *Burned in at upload* (Mux `overlay_settings`): Yekkola logo + professor name, identical for all viewers.
  - *Per-viewer overlay in the player*: viewer's name/phone drawn over the video by our web and mobile players, repositioning periodically. Mux has no per-viewer watermark, so this is ours — a deterrent against screen recording, not forensic tracing.
- **Offline (mobile):** downloaded through Mux's native SDKs as DRM-protected files with a persistent license. Never store plain media files on device.
- **Documents:** served via short-lived signed URLs; every download is stamped with the student's name/phone (per-student PDF watermark, generated on a queue and cached).
- **Devices:**
  - *Registered devices* (mobile apps — can hold offline downloads) are limited per account (admin setting, default 2).
  - *Web* does not count as a registered device but is limited to **concurrent streams** per account (admin setting, default 1).
  - Removing a device revokes its tokens and stops new licenses for it.
- **Revocation (be precise about this):** refunds, bans, and takedowns stop streaming immediately (no new tokens). A persistent offline license **cannot be killed remotely**; the app deletes revoked content at its next online sync, and in the worst case the content stops playing when its license expires. Keep the *offline license duration* setting short enough to make this acceptable (suggested 7–14 days).

## Consumption model (important — do not conflate)

- **Web:** DRM streaming playback via Mux Player.
- **Mobile (Flutter):** **download-then-watch is the primary pattern**, not streaming. This is the core accessibility differentiator. Downloads are DRM-protected (above), resumable, survive backgrounding, and progress is tracked offline and synced when back online.
- **Data cost is the real constraint**, not just connectivity:
  - Downloads use the lowest resolution tier the Mux native SDK offers (540p on Android, 720p tier on iOS at time of writing); students can choose a higher tier.
  - Always show download size before the student commits.
  - Audio lessons and documents should be promoted in the UI as the low-data option.

Do not default to a streaming-only mobile design — it undermines the product's core purpose.

## Video & audio (Mux)

Videos and audio are **not** stored on Laravel Cloud. Mux handles storage, transcoding, DRM, burned-in watermark, and delivery. Laravel stores only Mux asset/playback IDs and metadata.

- **Upload:** professors upload straight from the browser to Mux via direct-upload URLs issued by the API; Mux webhooks (`video.asset.ready`, errors) update `MediaAsset` status.
- **Playback (web):** Mux Player (React) with signed playback + DRM tokens, plus our per-viewer overlay.
- **Offline (mobile):** Mux DRM persistent licenses. The API signs the DRM token with `offline: true` and `licenseExpiration` = the admin *offline license duration* setting; `playDuration` optional.
- **Flutter gap:** Mux ships native players with offline-download managers for Android (`MuxDownloadManager`, Media3) and iOS (`MuxOfflineAccessManager`, AVFoundation), but **no Flutter SDK**. Phase 3 includes a small in-house Flutter plugin wrapping these native SDKs via platform channels (download, progress, delete, play with overlay). Prototype it early — it is the riskiest mobile piece.
- **Token signing:** `muxinc/mux-php` on the API; Mux signing keys live only in API env vars.
- Keep a `VideoProvider` interface (direct upload, webhook handling, playback/DRM tokens, offline token) so domain code doesn't depend on Mux directly.
- **To verify early:** DRM on audio-only assets; offline download of audio-only assets in the native SDKs; Mux pricing (encoding, storage, delivery, DRM licenses) against projected catalogue size.

## Business model

- Student pays via mobile money. Purchase is per course; coupons and bundles supported.
- **Free courses:** a professor can set any course's price to free. Enrolling is one tap — no order, payment, or ledger entry. Free courses still go through review, still use content protection, and still count toward certificates, reviews, and professor analytics. Free courses are the main lever for professors to build an audience before selling paid ones.
- Price changes are not retroactive: switching a course between free and paid never revokes existing enrollments.
- DRC mobile money rails: Orange Money, Airtel Money DRC, M-Pesa (Vodacom) — **not** MTN (that's the Uganda-side assumption from other projects; do not carry it over here).
- Aggregator candidates: Flutterwave, CinetPay, and DRC-local aggregators (e.g. MaxiCash, FreshPay). Must cover both collection **and** disbursement (for professor payouts) on all three rails.
- **Integration timing:** the real payment gateway is integrated later in the build (see *Build sequence*). Until then, orders, payment state machine, ledger, and payouts are built and tested against a `PaymentGateway` interface with a fake driver (simulates success, failure, pending, timeout, reversal). Plugging in the real aggregator must be a driver, not a redesign.
- Mobile money is asynchronous (USSD prompt, pending, timeout, reversal). Payments are a state machine driven by idempotent webhooks plus a reconciliation job — never trust the client redirect.
- Revenue split is stored as data (admin default, overridable per professor and per course), never hard-coded, and **snapshotted on each order item** at the moment of sale.
- **Money:** integer minor units **with an explicit currency** on every money row. Active currencies (USD, CDF, or both) are an admin setting — the schema must not assume one. No currency conversion inside the platform: a course is priced in one currency and paid in that currency.
- **Coupons:** each coupon records who funds the discount (platform, professor, or shared); the ledger reflects that.

## Tech stack

### System shape

```
                ┌─────────────────────────────┐
                │  Laravel API (pure JSON)    │  api.yekkola.*
                │  /api/v1 — single contract  │
                └──────────────┬──────────────┘
          ┌────────────────────┼────────────────────┐
   ┌──────┴──────┐      ┌──────┴──────┐      ┌──────┴──────┐
   │ Web app     │      │ Back office │      │ Mobile      │
   │ (Next.js)   │      │ (Next.js)   │      │ (Flutter)   │
   │ students +  │      │ admins +    │      │ students    │
   │ professors  │      │ moderators  │      │             │
   └─────────────┘      └─────────────┘      └─────────────┘
```

**The backend serves no UI.** No Blade views, no Inertia, no Filament, no server-rendered admin. Every client — web app, back office, mobile — is an equal consumer of the same `/api/v1`. (The only exceptions are ops tools for engineers, e.g. the Horizon dashboard, behind admin auth.) Full design: [`docs/architecture.md`](docs/architecture.md).

### Backend (API only)
- Laravel (current major release; pin the version at project start), installed as an API-only app, PHP 8.4+
- PostgreSQL; ULID primary keys (non-enumerable, safe in URLs)
- Laravel Sanctum — cookie-based SPA auth for the web app and back office (same parent domain); API tokens for mobile
- **Versioned JSON API (`/api/v1`)** with route groups by audience: `public`, `student`, `professor`, `admin`. Authorization via policies + roles, never by which client is calling.
- Laravel API Resources for response shapes; consistent envelope for pagination, errors, and validation messages
- Server-side filtering, sorting, and pagination on every list endpoint (`spatie/laravel-query-builder`) — the React tables depend on it
- **OpenAPI spec generated from code** (Scramble) — the source of truth for the typed TypeScript client and for Flutter
- Domain-oriented code: business logic in Action classes per domain; controllers stay thin
- Laravel Queues on Redis/Valkey + **Laravel Horizon** (production) — webhooks, notifications, PDF stamping, sync processing, stats rollups
- Laravel Storage / Flysystem — all non-Mux files via `Storage::disk()`
- `spatie/laravel-settings` — admin-configurable platform settings
- `spatie/laravel-translatable` — admin-managed `fr`/`en` fields
- Laravel Scout + Meilisearch — course and professor search
- `spatie/laravel-permission` — roles: student / professor / moderator / admin (+ finance permission set)
- `spatie/laravel-medialibrary` — documents, images, avatars (not video/audio)
- `spatie/laravel-activitylog` — audit log surfaced in the back office
- `spatie/laravel-backup` — on top of the platform's own DB backups
- Pest + PHPStan (Larastan) — tests and static analysis (feature tests per endpoint are the API contract)

### Web front ends (React)

Two Next.js apps sharing UI and API code:

- **apps/web** — Next.js (App Router). Public pages (catalogue, course pages, professor profiles, certificate verification) are server-rendered for SEO and fast first load on slow connections; authenticated areas (learning, professor studio) are client-side against the API.
- **apps/admin** — Next.js (App Router), almost entirely client components behind auth; no SEO. Next.js rather than a plain Vite SPA because Laravel Cloud deploys Next.js but not static sites — and it keeps one front-end framework to learn.
- **Shared across both:**
  - React + TypeScript (strict)
  - shadcn/ui + Tailwind — component-based; Yekkola branding lives as theme tokens in `packages/ui`
  - Orval — generates typed TanStack Query hooks + Zod schemas from the OpenAPI spec into `packages/api-client`
  - TanStack Query — server state, caching, optimistic updates
  - TanStack Table — data tables (server-side pagination, sorting, filtering, column visibility, row selection, bulk actions) via shadcn's data-table pattern
  - nuqs — table filters, sorting, and pagination kept in the URL (shareable, back-button safe)
  - shadcn charts (Recharts) — dashboards: KPI cards, revenue/enrollment trends, funnels
  - React Hook Form + Zod — forms and validation
  - next-intl — French (default) + English from shared catalogues
  - Mux Player (React) — DRM playback, plus our per-viewer watermark overlay component
- **Dashboards:** professor studio and admin dashboards are first-class features, backed by pre-aggregated stats endpoints — not computed in the browser.

### Mobile (Flutter)
- Riverpod — state management
- Dio — HTTP client against `/api/v1` (client generated from OpenAPI)
- flutter_secure_storage — auth tokens
- drift (SQLite) — offline DB for catalogue cache, entitlements, progress, sync queue
- In-house Flutter plugin wrapping Mux Player for Android / iOS — DRM playback and offline downloads (no official Mux Flutter SDK)
- background_downloader — document downloads
- Firebase Cloud Messaging + flutter_local_notifications
- ARB / `intl` — French + English
- Target: Android first-class (low-end devices, Android 8+); iOS supported

### Infrastructure
- **Hosting: Laravel Cloud.** No African region — use `eu-west-2` (London) or `eu-central-1` (Frankfurt); benchmark latency from several DRC cities (west, east, south) before choosing.
  - Three Cloud applications from the same repo (Cloud's monorepo support — each app is scoped to its directory):
    - `api/` — Laravel API instances + separate queue workers
    - `apps/web/` — Next.js (Node 20+)
    - `apps/admin/` — Next.js (Node 20+)
  - Each app scales independently and has its own env vars and domain
  - Domains on one parent domain (e.g. `api.`, `www.`, `admin.`) so Sanctum cookie auth works
  - PostgreSQL (Laravel Cloud managed), Valkey/Redis (Laravel Cloud managed), Laravel Cloud object storage bucket
  - Environments: `local`, `staging`, `production`
- Video/audio: Mux
- Search: Meilisearch Cloud
- Error tracking: Sentry (Laravel, Next.js, Flutter); uptime monitoring on API health endpoint
- Product analytics: PostHog (or equivalent) — event names defined in PRDs
- CI/CD: GitHub Actions (tests, lint, static analysis, contract check) → Laravel Cloud deploys

## Repository

**One repository** for backend, web, and (from phase 3) mobile. Full tree: [`docs/architecture.md`](docs/architecture.md#2-repository--file-structure).

```
yekkola/
  api/            Laravel API              → Laravel Cloud app "yekkola-api"
  apps/web/       Next.js public + student + professor studio → "yekkola-web"
  apps/admin/     Next.js back office      → "yekkola-admin"
  packages/       ui, api-client, i18n, config (shared)
  mobile/         Flutter app (phase 3)
  docs/           architecture + PRDs
```

- `api/` is a self-contained Laravel app (own `composer.json`); it is not part of the pnpm workspace.
- Front-end apps import `packages/*` through the pnpm workspace; builds run from the repo root via Turborepo. Verify on the first Cloud deploy.
- **Contract check in CI:** regenerate the OpenAPI spec and `packages/api-client` from `api/`; fail the build if the committed client is out of date. An API change and the front-end change that uses it land in the same PR.
- **CI path filters:** Pest/PHPStan run on `api/**` changes; lint/typecheck/tests run on `apps/**` and `packages/**` changes.
- Confirm how Cloud triggers deploys from a shared repo; if a push redeploys all three apps, switch to deploy hooks called from GitHub Actions with path filters.

## Architecture decisions worth preserving

1. **Storage is abstracted.** Files go through `Storage::disk()`; video/audio go through `VideoProvider`. Swapping providers is a config/adapter change.
2. **Media never touches the app server.** Professors upload directly to Mux (direct upload URLs) and to object storage (pre-signed URLs); Laravel only receives webhooks/confirmations.
3. **API-only backend, one contract for all clients.** Web, back office, and mobile consume the same `/api/v1`. Nothing a client needs may exist only in a UI layer. The OpenAPI spec is generated from code and every endpoint has feature tests.
4. **No business logic in the front ends.** Prices, access rules, splits, limits, and permissions are decided by the API; React and Flutter display and submit. The back office is just another API client with admin permissions.
5. **External integrations behind interfaces** (`VideoProvider`, `PaymentGateway`, `SmsSender`), each with a fake driver used in tests and local dev.
6. **What's hard to change later:** core data model (users, courses, versions, lessons, enrollments, orders, ledger), auth/identity approach, money/currency representation, content-protection model. Spend design effort here first.
7. **Ledger is double-entry and append-only.** Every sale, platform fee, refund, and payout is a balanced ledger transaction; balances are derived, never edited. Corrections are new reversing entries.
8. **Idempotency everywhere money or webhooks are involved.** Order creation requires an `Idempotency-Key`; every webhook is deduplicated by provider event ID before processing.
9. **Stateless API.** No local disk, no in-process state — sessions, cache, locks, and queues live in Redis/Postgres, so API instances scale horizontally.

## Identity & auth

- **Phone number (E.164) + SMS OTP is the primary sign-in**; email is optional (for receipts/recovery).
- SMS gateway must have confirmed DRC deliverability — do not assume Uganda-side providers work.
- Until the SMS gateway is integrated, OTPs go through an `SmsSender` interface with a **log driver** (codes written to the log; fixed test codes allowed only outside production). Production must refuse to boot with the log driver.
- OTP abuse protection: per-phone and per-IP rate limits, attempt limits, and a bot challenge (e.g. Cloudflare Turnstile) on web — SMS pumping costs real money.
- Device registry per user (device limits, push tokens, token revocation).
- Details: [`docs/prd/01-auth-identity.md`](docs/prd/01-auth-identity.md).

## Feature scope

Each area has a PRD in [`docs/prd/`](docs/prd/).

| # | Area | Highlights |
|---|---|---|
| 01 | Auth & identity | Phone OTP, devices, roles, account deletion |
| 02 | Professor onboarding | Application, manual vetting, KYC, public profile |
| 03 | Course authoring | Course builder, 4 lesson types, Mux upload, versioning, review submission |
| 04 | Discovery | Catalogue, categories, search, filters, course & professor pages, wishlist, SEO |
| 05 | Checkout & payments | Free enroll, orders, mobile money, coupons, bundles, parent payer, refunds, receipts |
| 06 | Learning experience | Player, progress, quizzes, certificates, reviews, lesson Q&A |
| 07 | Content protection & offline | DRM, watermarks, device limits, offline downloads & sync |
| 08 | Earnings & payouts | Ledger, professor earnings, payout batches, statements |
| 09 | Back office | Admin dashboard, moderation, users, finance, settings, audit |
| 10 | Notifications | Push, SMS, email, in-app; preferences; templates |

**Platform (cross-cutting):** rate limiting on auth, OTP, and payment endpoints; terms of service, privacy policy, professor agreement (content ownership + licence to Yekkola); support channel (in-app contact / WhatsApp link); account deletion and data export.

## Non-functional requirements

- **Performance (web):** public pages LCP < 2.5 s on a slow 3G profile; initial JS for public pages kept small (server components by default, no heavy client libraries on marketing pages). Images via responsive, compressed formats.
- **Performance (API):** p95 < 300 ms for read endpoints, < 800 ms for writes (excluding external calls, which go to queues).
- **Mobile:** usable on 2 GB RAM Android devices; app size kept small; every screen has an offline state.
- **Availability:** 99.9% target for the API; payment and Mux webhooks must tolerate API downtime (providers retry; reconciliation jobs catch the rest).
- **Accessibility:** WCAG 2.1 AA for web; keyboard navigable back office.
- **Security & privacy:** OWASP ASVS L2 as the bar; PII (phone, KYC documents) encrypted at rest where stored; KYC documents in a private bucket, never public URLs; least-privilege admin roles; full audit log of admin actions. Comply with DRC data-protection law (confirm with counsel).
- **Scalability:** designed for horizontal scale to millions of users — see [`docs/architecture.md`](docs/architecture.md#7-scalability).

## Build sequence

1. **Backend** — data model, domain actions, Mux integration (direct upload, webhooks, DRM tokens), auth/OTP, orders + payment state machine + ledger + payouts against fake gateway drivers, full `/api/v1` (public, student, professor, admin) with feature tests and generated OpenAPI spec.
2. **Web** — shared `ui` / `api-client` / `i18n` packages, then the back office (needed to vet professors and approve content before launch) and the web app (public site, student learning, professor studio).
3. **Mobile** — Flutter app against `/api/v1`, offline-first with DRM downloads (incl. the Mux native-SDK plugin, prototyped early).

**Integrated later (deferred, not dropped):** real SMS gateway and real payment aggregator. Both are built behind interfaces from day one (`SmsSender`, `PaymentGateway`) with fake/log drivers, so they slot in whenever chosen without touching domain code. Free courses work end to end without a payment gateway. **Both must be live before public launch** — sign-in depends on SMS, paid courses on payments.

## Out of scope

- Live sessions / live Q&A
- Institutional / school bulk licensing
- UI languages beyond French and English
- Subscriptions / all-access passes (per-course purchase only)
- Professor-to-student direct messaging outside lesson Q&A

## Platform settings (admin-configurable)

These are business decisions the admin makes and changes at runtime from the React back office (via admin API endpoints) — **not** values to hard-code or decide in code. Store them in the database (`spatie/laravel-settings`), seed sensible defaults, and record every change in the audit log.

| Setting | Default | Notes |
|---|---|---|
| Enabled currencies + default currency (USD / CDF) | USD | Code supports both; each price and money row keeps its own currency. |
| Default revenue split | (to set) | Overridable per professor and per course; snapshotted per order item. |
| Content categories | — | Admin creates, edits, orders, and hides categories (fr/en names). |
| Separate payer on orders (parent pays for student) | on | Feature is built; admin turns it on/off. |
| Registered device limit per account | 2 | Mobile devices that can hold downloads. |
| Concurrent web streams per account | 1 | |
| Offline license duration | 14 days | How long downloads play before the app must re-validate online. |
| Free-course limits | unlimited | Max free courses and/or free media hours per professor. |
| Payment gateways / rails enabled | — | Pause a gateway or rail during an outage. Adding a gateway is dev work. |
| Refund window | 7 days | Days after purchase a student can request a refund (and max progress %, e.g. 20%). |
| Payout minimum + schedule | (to set) | Minimum balance to be paid out; weekly/bi-weekly/monthly batch cadence. |
| Earnings hold period | 7 days | Sale earnings become payable after the refund window, protecting against refunds. |
| Order payment timeout | 15 min | Pending mobile-money payments expire after this. |
| Certificate completion rule | 100% lessons + all quizzes passed | Adjustable threshold. |

Smaller operational settings (preview lessons per course, upload size limits, price min/max, review progress threshold, reapply cooldown, etc.) are defined in the PRDs and live in the same settings store.

Rule: a settings change applies **going forward only**. It must never alter existing orders, ledger entries, enrollments, or published prices.

## Open decisions (flag, don't assume)

Technical decisions — integrations depend on them. Deferred (see *Build sequence*) but must be settled before public launch:

- Payment aggregator(s) to integrate (pending DRC rail + disbursement confirmation)
- SMS gateway (pending DRC deliverability test)

Business/legal — needed before launch, do not invent values in code:

- Default revenue split and payout schedule (become settings once decided)
- Tax treatment (VAT on sales, withholding on professor payouts) — confirm with a DRC accountant
- Legal entity, terms of service, privacy policy, professor agreement — confirm with counsel
- Minimum age for accounts and how minors' data is handled
