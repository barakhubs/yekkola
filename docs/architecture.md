# Yekkola — Architecture

> Technical design for the API, web apps, and mobile app. Product rules live in [`project-context.md`](../project-context.md) and the [PRDs](prd/); this doc says how the system implements them.
> Status: design baseline — update in the same PR as any change that contradicts it.

## Contents

1. [System architecture](#1-system-architecture)
2. [Repository & file structure](#2-repository--file-structure)
3. [Database schema](#3-database-schema)
4. [API](#4-api)
5. [Web UI architecture](#5-web-ui-architecture)
6. [Mobile architecture](#6-mobile-architecture)
7. [Scalability](#7-scalability)
8. [Security](#8-security)
9. [Observability & operations](#9-observability--operations)
10. [Testing strategy](#10-testing-strategy)

---

## 1. System architecture

### 1.1 Components

```
                         Students / Professors / Admins
                 ┌──────────────┬───────────────┬──────────────┐
                 │ apps/web     │ apps/admin    │ mobile       │
                 │ Next.js      │ Next.js       │ Flutter      │
                 │ (Cloud app)  │ (Cloud app)   │ (stores)     │
                 └──────┬───────┴───────┬───────┴──────┬───────┘
                        │ HTTPS JSON /api/v1 (cookie or bearer)
                 ┌──────▼──────────────────────────────▼───────┐
                 │  api/  Laravel (Cloud app, N instances)     │
                 │  HTTP: controllers → actions → models       │
                 └──┬─────────┬──────────┬──────────┬──────────┘
                    │         │          │          │
              ┌─────▼──┐ ┌────▼────┐ ┌───▼─────┐ ┌──▼───────────┐
              │Postgres│ │ Valkey  │ │ R2      │ │ Meilisearch  │
              │        │ │ cache,  │ │ private │ │ (search)     │
              │        │ │ queues, │ │ + public│ │              │
              │        │ │ locks   │ │ buckets │ │              │
              └────────┘ └────┬────┘ └────▲────┘ └──────────────┘
                              │           │ HLS output
                    ┌─────────▼──────────┐│
                    │ Queue workers      │┘ FFmpeg: HLS AES-128 + watermark
                    │ (Horizon)          │──► Payment gateway (collect, disburse)
                    │                    │──► SMS gateway, email, FCM
                    └────────────────────┘
   Inbound webhooks: Gateway ──► /webhooks/payments/{gateway}
   Media upload:     browser ──► R2 private bucket (pre-signed URL), then upload-complete call to the API
   Media delivery:   playlists + keys from the API; segments from R2 (short-lived pre-signed URLs)
```

### 1.2 Backend domains (bounded contexts)

| Domain | Owns | Key models |
|---|---|---|
| `Identity` | Users, OTP, sessions/tokens, devices, roles | User, OtpChallenge, Device |
| `Professors` | Applications, vetting, KYC, profiles | ProfessorApplication, ProfessorProfile |
| `Catalog` | Categories, courses (public view), search indexing | Category, Course |
| `Authoring` | Versions, sections, lessons, media, quizzes, review submission | CourseVersion, Section, Lesson, MediaAsset, Quiz |
| `Commerce` | Quotes, orders, payments, coupons, bundles, refunds | Order, OrderItem, Payment, Coupon, Bundle, Refund |
| `Learning` | Enrollments, progress, quiz attempts, certificates, reviews, Q&A, wishlist | Enrollment, LessonProgress, QuizAttempt, Certificate, Review, LessonThread |
| `Protection` | HLS playlists and keys, offline licenses, document stamping, stream limits | OfflineLicense, StreamSession |
| `Finance` | Ledger, balances, payouts | LedgerAccount, LedgerTransaction, LedgerEntry, Payout, PayoutBatch |
| `Moderation` | Course review queue, reports, takedowns | Report (+ review fields on CourseVersion) |
| `Notifications` | Channels, templates, preferences | NotificationPreference |
| `Platform` | Settings, audit, stats rollups | settings, activity_log, CourseDailyStat |

Rules: domains talk to each other through **actions and events**, not by reaching into each other's tables in controllers. Cross-domain side effects (e.g. "payment succeeded → create enrollment → post ledger → notify") are driven by domain events handled by queued listeners.

### 1.3 Key flows

**A. Sign-in (phone OTP)**
1. `POST /auth/otp/request` → rate-limit check (phone + IP) → create `OtpChallenge` (hashed code, 5 min TTL) → `SmsSender` (queued).
2. `POST /auth/otp/verify` → verify hash, attempts ≤ 5 → find-or-create `User` → web: session cookie via Sanctum; mobile: register/refresh `Device`, issue a token bound to that device.

> Flows B and D run on the R2 driver (owner decision, 2026-11-09). Flow E (offline) is built (app side: staging-only prototype screens until the downloads manager).

**B. Video upload (professor)**
1. Studio calls `POST /professor/courses/{id}/draft/lessons/{lid}/media {size_bytes, content_type}` → `MediaUploads::start` checks the size limit (platform setting) and type, creates a `MediaAsset` (`waiting_upload`), opens an R2 multipart upload (10 MB parts, more for huge files, at most 10,000 parts) and returns the first 20 pre-signed part URLs.
2. Browser PUTs the parts **directly to R2** (3 at a time, each retried with backoff, paused while offline), reading each `ETag`; more URLs, or a fresh one for an expired URL, come from `POST /professor/media/{id}/parts`. Media never touches the app server. The private bucket's CORS must allow `PUT` from the studio origin and expose `ETag`, and allow `GET`/`HEAD` from the web origins (hls.js fetches segments cross-origin, without credentials).
3. Studio calls `POST /professor/media/{id}/complete {parts: [{number, etag}]}` → R2 assembles the object (missing parts → `media.upload_incomplete`; the studio can send them and confirm again) → `processing` → `ProcessMediaAsset` job on the `media` queue (unique per asset; `redis-media` connection with a `retry_after` above the job timeout, own Horizon supervisor `supervisor-media`). A repeated complete is harmless.
4. The job (`R2VideoProvider::process` + `FfmpegTranscoder`) downloads the source to the worker and runs FFmpeg: video → H.264/AAC HLS renditions 720p/480p/360p (never upscaled; the lowest is always made) + a poster frame; audio → mono AAC audio-only HLS. Segments are AES-128 encrypted with one random key per asset (the key file is never uploaded). The burned-in watermark (logo PNG if configured + "Yekkola · professor name") is drawn by FFmpeg. Output goes to `media/{id}/hls/{run}/` in the private bucket (one folder per run, so a duplicate run never mixes segments and keys); the asset becomes `ready` with its renditions, key and duration, and the duration is pushed onto open versions' lessons. A file FFmpeg can't read → `errored` at once (the professor sees a translated message; the reason is logged). R2 or worker failures are retried twice. The source is kept for now (cleanup: TODO 1.5).
5. Studio polls `GET /professor/media/{id}` (or listens via notifications) to show status.

**C. Paid purchase (mobile money)**
1. `POST /checkout/quote` → server computes price, discounts, currency (never trusts client totals).
2. `POST /orders` with `Idempotency-Key` → `Order(pending)` + items with **snapshotted** price and revenue split.
3. `POST /orders/{id}/payments {rail, msisdn}` → `PaymentGateway::collect()` → `Payment(pending)`; student approves the USSD prompt on their phone.
4. Gateway webhook → `/webhooks/payments/{gateway}` → verify signature → dedupe by event id → re-query the gateway **by our payment reference** (never trust the webhook body alone) → compare the confirmed amount/currency with the payment → `Payment(succeeded)`. A start call that times out (`GatewayOutcomeUnknown`) stays pending and is reconciled the same way — never retried under a new reference.
5. `PaymentLifecycle::apply()` (one transaction, order + payment locked, safe to replay) → `Order(paid)` → `Enrollment` per item → ledger `sale` transaction (gateway clearing ↔ professor held + platform revenue) → `OrderPaid` event (receipt notification, PRD-10). Access and the sale are per item: only courses newly granted are sold. Money collected that buys nothing (a confirmed amount that differs, a second success, items already owned) is posted as `unapplied_receipt` (gateway clearing ↔ refunds payable) and flagged for staff; an amount mismatch blocks further attempts on the order. A reversal revokes the access that payment granted and mirrors its postings. One open order per course and buyer.
6. Client polls `GET /orders/{id}` until terminal state; an in-flight payment is re-checked with the gateway at most every 10 s, and an order past its payment deadline is expired on that poll (after the re-check, so a last-second success still wins). `commerce:reconcile` (every 5 min) expires orders past the payment timeout, re-queries payments in flight > 2 min, and checks failed/expired ones hourly for 24 h (late success → access granted, order flagged `late_success`). Each row is isolated: one failure never stops the run.
7. `finance:release-earnings` (hourly) posts one `release` per sold item (reference `order_item`) once the earnings hold period has passed: professor held → available. Refunds and reversals take the professor share from available once the item is released (it may go negative and is netted against later earnings), from held before; all three run under the order row lock.

**D. Playback (streaming)**
1. Player requests `POST /lessons/{uid}/playback`.
2. API checks: active enrollment (or preview lesson), account status, concurrent-stream limit (web) or registered device (mobile).
3. Returns the master playlist URL on the API (with `?session=` for web streams), a poster URL, and the watermark text (+ web `stream.session_id`).
4. The player (hls.js; Safari natively) loads `GET /lessons/{uid}/hls/master.m3u8`, then `/hls/{rendition}.m3u8` and `/hls/key`, with credentials. **Every one of these requests repeats the access check** (`AuthorizeMedia`): enrollment or preview, active account, and for browsers a live stream session for that user and lesson. A replaced stream, refund or takedown therefore stops new playlists and keys at once. Media playlists are read from R2 with segment names swapped for pre-signed GET URLs. These are valid for the playback window + 2 × the lesson length, at most 6 h, and cached signed for half the window since they aren't viewer-specific. A player that already holds the playlist and key can finish the lesson within that window: the accepted limit of encrypted HLS without DRM. The key URI points at `/hls/key`, which returns the 16-byte key (`no-store`). Segments stream **directly from R2**; no DRM (encrypted HLS, accepted by the owner, 2026-11-09).

**E. Offline download (mobile)**
1. App requests `POST /lessons/{uid}/offline-license {rendition?}` with its device token and `X-Device-Id` → `IssueOfflineLicense` checks the token's device is active and is that install, the lesson is video/audio and the enrollment is active (takedowns included) → returns the chosen rendition's media playlist (our lowest by default; signed segment URLs valid at least 3 h, reported as `segments_expire_at`), the AES key (base64), `expires_at` = now + the _offline license duration_ setting, and the overlay text → upserts `OfflineLicense` (one per device and lesson). Asking again renews it (new expiry, fresh segment URLs): that is also how the app resumes a download whose URLs ran out. Removing a device (or deleting the account) revokes its licenses (`RevokeOfflineLicenses` on `DeviceRevoked`).
2. The app (`lib/features/media`) wraps the key at once (AES-256-GCM under a per-install key in secure storage; bound to license, lesson, media and expiry, so an edited expiry breaks it), keeps the playlist as a local template without the key, and fetches the encrypted segments one by one with a plain Dio (no API token or install ID to the storage host) into app-private storage, resumably. To play, it unwraps the key into memory and serves playlist, key and segments to `video_player` (ExoPlayer / AVPlayer, AES-128 HLS) from a loopback-only server with a random per-playback token; overlay on top, `FLAG_SECURE` on Android. Plain media and plain keys are never written to disk. A device clock set back before the last playback counts as expired.
3. Entitlement sync: when online (app start, reconnect, resume, sign-in and the 1-minute timer; at most every 30 min, never without downloads) the app calls `GET /me/entitlements` (`ListEntitlements`, same registered-device check). For each of the device's licenses the API decides `keep`, `renew` (expired, or less than half the license duration left) or `delete` (`revoked`, `no_access` — refund, enrollment ended, takedown, lesson gone from the live version —, `media_changed`, `media_unavailable` — media no longer ready or the license's quality removed). The app follows, deletions first (a failing renewal never keeps a revoked download), then renewals via step 1, and also deletes downloads the API doesn't list. It touches nothing if the account changed while the request was in flight. A download can't be killed remotely: in the worst case it stops playing when `expires_at` passes.

**F. Progress sync (offline-first)**
1. App writes progress locally (drift) with a client timestamp.
2. When online: `PUT /me/progress` with a batch → server upserts per `(user, lesson_uid)`, keeping the furthest position / completed state (monotonic merge, never regress completion).
3. Completion events trigger certificate eligibility checks (queued).

**G. Payout run**
1. `finance:build-payout-batches` (daily; builds on the days the schedule setting says) or `POST /admin/payout-batches` builds a `PayoutBatch(draft)` per currency: professors active, KYC verified, with a payout method, not under the method-change hold, whose **available** balance ≥ the minimum. Each line pays the balance rounded down to the currency's step (whole francs for CDF); the rest stays available. One open batch per currency.
2. Finance (`finance.payouts`) leaves lines out with a reason, then approves (never a batch that pays the approver) → `ExecutePayout` per line on the `payments` queue: eligibility, payout method and balance are checked again, then `PaymentGateway::disburse()` under `P-{payout id}`.
3. Webhook (`P-` disbursement events) or `finance:sync-payouts` (every 5 min) → status API → `Payout(paid|failed)`. Only a confirmed payout is posted (`payout`: professor available → gateway clearing, fee to `gateway_fees` or the professor per setting). A failed payout posts nothing (the money stays available); its professor is left out of runs for 48 h in case the gateway reports a late success (still posted, logged critical). A reversal after success is mirrored (`payout_failed`) and can't turn back into a success. The sync also sends lines an approved batch left pending (lost job). A refund approved while a payout is in flight, or a fee borne by the professor, can take available below zero: carried forward (FR-05).

---

## 2. Repository & file structure

```
yekkola/
├── api/                                   Laravel API (Cloud app "yekkola-api")
│   ├── app/
│   │   ├── Domain/
│   │   │   ├── Identity/
│   │   │   │   ├── Actions/               RequestOtp, VerifyOtp, RegisterDevice, RevokeDevice, DeleteAccount
│   │   │   │   ├── Events/
│   │   │   │   ├── Models/                User, OtpChallenge, Device
│   │   │   │   ├── Policies/
│   │   │   │   └── Enums/                 UserStatus, DevicePlatform
│   │   │   ├── Professors/                Actions: SubmitApplication, ApproveApplication, RejectApplication, UpdateProfile, SuspendProfessor
│   │   │   ├── Catalog/                   Actions: SearchCourses, FeatureCourse; Search/CourseIndexer
│   │   │   ├── Authoring/                 Actions: CreateCourse, StartDraftVersion, UpsertSection, UpsertLesson, ReorderCurriculum,
│   │   │   │                              CreateMediaUpload, CompleteMediaUpload, TranscodeMedia, SubmitForReview, ApproveVersion, RejectVersion
│   │   │   ├── Commerce/                  Actions: QuoteCheckout, CreateOrder, InitiatePayment, HandlePaymentResult,
│   │   │   │                              ExpireStaleOrders, RequestRefund, ApproveRefund; StateMachines/OrderState, PaymentState
│   │   │   ├── Learning/                  Actions: EnrollFree, GrantEnrollment, RevokeEnrollment, SyncProgress,
│   │   │   │                              StartQuizAttempt, SubmitQuizAttempt, IssueCertificate, PostReview, PostThread
│   │   │   ├── Protection/                Actions: IssuePlayback, ServeHlsPlaylist, IssueMediaKey, IssueOfflineLicense, StampDocument, EnforceStreamLimit
│   │   │   ├── Finance/                   Ledger/(Ledger, Posting, AccountType), Actions: PostSale, PostRefund,
│   │   │   │                              BuildPayoutBatch, ApprovePayoutBatch, ExecutePayout, HandlePayoutResult
│   │   │   ├── Moderation/                Report, Actions: FileReport, ResolveReport (takedown: Authoring/ChangeCourseAvailability)
│   │   │   ├── Notifications/             Notifications/*, Channels/(SmsChannel, FcmChannel)
│   │   │   └── Platform/                  Settings/*Settings.php, Stats/RollupCourseStats
│   │   ├── Integrations/
│   │   │   ├── Video/                     VideoProvider (interface), R2VideoProvider + FfmpegTranscoder, HlsPlaylist, FakeVideoProvider
│   │   │   ├── Payments/                  PaymentGateway (interface), FakeGateway, {Aggregator}Gateway (later)
│   │   │   └── Sms/                       SmsSender (interface), LogSmsSender, {Provider}SmsSender (later)
│   │   ├── Http/
│   │   │   ├── Controllers/Api/V1/
│   │   │   │   ├── Auth/
│   │   │   │   ├── Public/
│   │   │   │   ├── Student/
│   │   │   │   ├── Professor/
│   │   │   │   ├── Admin/
│   │   │   │   └── Webhooks/
│   │   │   ├── Requests/                  Form requests mirrored by audience
│   │   │   ├── Resources/                 API Resources (JSON shapes)
│   │   │   └── Middleware/                SetLocaleFromHeader, EnsureAccountActive, EnsureDeviceRegistered, EnsureIdempotency (alias `idempotent`)
│   │   ├── Jobs/                          Cross-domain jobs (reconciliation, rollups)
│   │   └── Providers/
│   ├── config/  database/(migrations, factories, seeders)  lang/(fr, en)
│   ├── routes/api_v1.php                  Route groups: auth, public, student, professor, admin, webhooks
│   ├── tests/(Feature/Api/V1/..., Unit/Domain/...)
│   └── composer.json
├── apps/
│   ├── web/                               Next.js — public + student + professor studio
│   │   ├── src/app/[locale]/
│   │   │   ├── (marketing)/               /, /courses, /courses/[slug], /categories/[slug], /professors/[slug],
│   │   │   │                              /certificates/[serial], /teach, /legal/(terms|privacy|professor-agreement) ← server components, SSR/ISR
│   │   │   │                              /courses/[slug]/gift, /gift/[orderId]#token  ← guest checkout (noindex)
│   │   │   │                              /bundles/[slug]                                ← bundle offer
│   │   │   ├── (auth)/                    /login, /verify
│   │   │   ├── (student)/                 /my-courses, /learn/[course]/[lesson], /checkout/[orderId],
│   │   │   │                              /orders, /my-certificates, /wishlist, /notifications, /account ← client, auth-guarded
│   │   │   └── (studio)/studio/           /, /apply, /courses, /courses/[id]/(details|curriculum|pricing|review),
│   │   │                                  /analytics, /earnings, /promotions (coupons + bundles), /payouts, /questions, /reviews, /profile, /verification
│   │   ├── src/features/<domain>/         components/, hooks/, schemas/, utils/
│   │   ├── src/lib/                       api (fetch config), auth, i18n, analytics
│   │   └── messages/ → packages/i18n
│   └── admin/                             Next.js — back office
│       ├── src/app/[locale]/
│       │   ├── (auth)/login
│       │   └── (console)/                 /dashboard, /users, /professors[/id], /kyc, /applications,
│       │                                  /moderation/(courses|reports|reviews), /catalog/(courses|categories|featured),
│       │                                  /orders, /coupons, /bundles,
│       │                                  /finance/(ledger|payouts|refunds), /settings, /audit-log, /staff
│       └── src/features/<domain>/
├── packages/
│   ├── ui/                                shadcn/ui primitives + blocks (DataTable, KpiCard, ChartCard, PageHeader,
│   │                                      EmptyState, FileUpload, ConfirmDialog, StatusBadge, MoneyText), theme tokens
│   ├── api-client/                        Orval output: typed fetchers, TanStack Query hooks, Zod schemas
│   ├── i18n/                              fr.json, en.json (namespaced), shared formatters (money, dates, phone)
│   └── config/                            tsconfig, eslint, tailwind preset, prettier
├── mobile/                                Flutter (phase 3)
│   ├── lib/
│   │   ├── core/                          config (flavors), api (Dio, interceptor, generated client), storage, device, i18n, theme, router; later db (drift), sync engine
│   │   ├── features/<feature>/            data/, domain/, presentation/  (auth, account; media: HLS downloader + key store)
│   │   ├── l10n/                          app_fr.arb (template), app_en.arb
│   │   └── main.dart
│   └── tool/generate_api_client.dart      cuts api/openapi.json to student operations, runs swagger_parser
├── docs/                                  architecture.md, prd/
├── loadtest/                              k6 load tests (README.md; test students from `php artisan loadtest:prepare`)
├── .github/workflows/                     api.yml, web.yml, contract.yml, mobile.yml
├── package.json  pnpm-workspace.yaml  turbo.json
└── project-context.md
```

Routes use English path segments under a locale prefix (`/fr/...` default, `/en/...`). Course and professor slugs come from their (usually French) titles, which carries the SEO value.

---

## 3. Database schema

PostgreSQL. Conventions:

- Primary keys: ULID (`id char(26)`). Foreign keys indexed.
- Timestamps: `created_at`, `updated_at` (UTC). Soft deletes (`deleted_at`) only where noted.
- Money: `*_minor bigint` + `currency char(3)` on the same row. Revenue shares in basis points (`*_bps int`, 10000 = 100%).
- Enums stored as `varchar` with PHP backed enums (easier to evolve than Postgres enums).
- Translatable admin text: `jsonb` `{ "fr": "...", "en": "..." }`.
- JSON payloads from providers: `jsonb`.

### 3.1 Identity

| Table | Columns (key) | Notes |
|---|---|---|
| `users` | id, phone_e164 (unique, 40 chars — anonymised rows hold `deleted:<ulid>`), phone_verified_at, email (unique, null), email_verified_at, name, locale (`fr`/`en`), province_id (null), city (null), status (`active`/`suspended`/`banned`), auth_epoch, last_login_at, last_seen_at, deletion_requested_at, deleted_at | Phone is the identity; no password. `auth_epoch` is bumped to end every web session ("sign out everywhere"). |
| `otp_challenges` | id, phone_e164, purpose (`login`/`change_phone`), user_id (change_phone), code_hash (HMAC, never the code), attempts, expires_at, consumed_at, ip, user_agent | Pruned after 24 h (`model:prune`). A new code consumes earlier unused ones. |
| `devices` | id, user_id, platform (`android`/`ios`), install_id (unique per user), name, app_version, push_token, registered_at, last_active_at, revoked_at | Counts toward the registered device limit when `revoked_at` is null. |
| `personal_access_tokens` | Sanctum, ULID id, + `device_id`, `expires_at` (90 days) | One token per device; revoking the device deletes it. |
| `data_exports` | id, user_id, status (`pending`/`ready`/`failed`), path (private disk), ready_at, expires_at | Personal-data exports (PRD-01 FR-12), downloadable for 7 days. |
| `stream_sessions` | id, user_id, client (`web`/`android`/`ios`), lesson_uid, started_at, last_heartbeat_at, ended_at | Enforces concurrent web streams. Short-lived; can live in Redis instead. |
| `provinces` | id, code (ISO 3166-2:CD, unique), name | Seeded with the 26 DRC provinces (`ProvinceSeeder`); `users.province_id` is optional. |
| `roles`, `permissions`, … | spatie/laravel-permission (guard `web`; `model_id` is a ULID) | Roles and permissions come from the `Role`/`Permission` enums. Roles: student, professor, moderator, admin, super-admin. Finance permissions (`finance.*`) are granted individually. |

### 3.2 Professors

| Table | Columns (key) | Notes |
|---|---|---|
| `professor_applications` | id, user_id, status (`draft`/`submitted`/`under_review`/`needs_info`/`approved`/`rejected`/`expired`), full_name, subjects (jsonb), levels (jsonb), years_experience, institutions, credentials (text), sample_url, motivation, province_id, terms_version, submitted_at, reviewer_id, review_started_at, info_request_note, info_requested_at, decision_note, decided_at | Partial unique index: one open application per user. Credential files in `media` (collection `credentials`, private disk). |
| `professor_profiles` | id, user_id (unique), application_id, slug (unique), slug_changed_at, display_name, headline, bio, subjects, links (jsonb), avatar (media, public disk), province_id, status (`active`/`suspended`), suspended_at, suspension_reason, kyc_status (`none`/`pending`/`verified`/`rejected`), verified_at, revenue_share_bps (null = default), payout_rail, payout_msisdn (encrypted), payout_name, payout_method_updated_at, payout_hold_until, rating_avg, rating_count, student_count | Created on application approval. Payable = active + KYC verified + payout method + no hold. |
| `kyc_submissions` | id, professor_profile_id, document_type (`national_id`/`voter_card`/`passport`), status (`pending`/`verified`/`rejected`), reviewed_by, reviewed_at, rejection_reason | Files in `media` (collections `front`, `back`, `selfie`, private disk), streamed only to `kyc.view` staff, every view audited. |
| `terms_acceptances` | id, user_id, document (`professor_agreement`), version, accepted_at, ip | Append-only. Current versions in `config/yekkola.php` → `terms`. |
| `media` | spatie/laravel-medialibrary (bigint id, `uuid` used in URLs, ULID `model_id`) | Avatars on `yekkola.public_disk` (R2 public bucket), credentials and KYC on `yekkola.private_disk` (R2 private bucket); both are S3-compatible disks in production (boot fails on a local disk). KYC is only ever streamed through the API. Erased with the account (`UserAnonymizing` event). |

### 3.3 Catalog & authoring

| Table | Columns (key) | Notes |
|---|---|---|
| `categories` | id, parent_id (null), slug, name (jsonb fr/en), description (jsonb), icon, position, is_visible | Two levels max. Seeded by `CategorySeeder` from docs/catalogue-categories.md. |
| `courses` | id, professor_profile_id, slug (unique), status (`draft`/`in_review`/`published`/`unpublished`/`archived`), live_version_id, draft_version_id, category_id, language, level, audience (jsonb), is_free, price_minor, currency, revenue_share_bps, featured_position (null = not featured), published_at, rating_avg, rating_count, enrollment_count, total_duration_seconds, deleted_at, taken_down_at, takedown_reason | Settings (price, category…) apply immediately; content lives in versions. *Listed* (public) = published + live version + active professor and account. Indexed in Meilisearch (`Searchable`: title, subtitle, description, outcomes, professor name, category names, audience). Taken down (PRD-09 FR-08): unpublished and closed to enrolled students too — `CourseAccess::activeEnrollment` refuses with `enrollment.course_taken_down`, so no new playback or document tokens. |
| `course_versions` | id, course_id, number, state (`draft`/`in_review`/`rejected`/`approved`/`superseded`), title, subtitle, description, outcomes, requirements, changelog, cover (media, public disk), submitted_at, reviewed_by, reviewed_at, review_notes | Partial unique index: one draft/in-review/rejected version per course. Approved versions are never edited. |
| `sections` | id, course_version_id, title, position | |
| `lessons` | id, course_version_id, section_id, uid (stable across versions), type (`video`/`audio`/`document`/`quiz`), title, description, position, is_preview, duration_seconds, media_asset_id | Unique `(course_version_id, uid)`. Document PDF in `media` (collection `document`, private disk). |
| `media_assets` | id, professor_profile_id, kind (`video`/`audio`), provider (`r2`/`fake`), provider_upload_id (R2 multipart upload id), source_key, size_bytes, output_prefix, renditions (jsonb `[{name, bandwidth, size_bytes, width, height}]`, highest first), encryption_key (encrypted cast, base64 of the AES-128 key; hidden from serialization), has_poster, status (`waiting_upload`/`processing`/`ready`/`errored`/`cancelled`), duration_seconds, error (technical reason, staff only) | Reused across versions (a new draft doesn't re-upload). Source at `media/{id}/source`, HLS at `media/{id}/hls/{run}/{rendition}/` + `poster.jpg` in the private bucket. The key is never stored in R2. Status: `waiting_upload` → `processing` → `ready` / `errored`. |
| `quizzes` | id, lesson_id (unique), pass_mark_pct, time_limit_seconds, max_attempts, shuffle_questions | One per quiz lesson; copied with the lesson. |
| `quiz_questions` | id, quiz_id, type (`single`/`multiple`/`true_false`), prompt, explanation, points, position | |
| `quiz_options` | id, quiz_question_id, text, is_correct, position | `is_correct` never sent to students before submission. |

### 3.4 Commerce

| Table | Columns (key) | Notes |
|---|---|---|
| `orders` | id, number (`YK-XXXXXXXX`, unique), buyer_id (null = parent without an account), beneficiary_id (student who gets access), payer_msisdn (encrypted, optional), guest_token_hash (sha256 of the guest token — an HMAC of the student and the client's idempotency key, so retries get it back; valid 30 days), status (`pending`/`awaiting_payment`/`paid`/`expired`/`cancelled`/`reversed`/`refunded`/`partially_refunded`), currency, subtotal_minor, discount_minor, total_minor, coupon_id, bundle_id, idempotency_key (unique per buyer; per student for guests), expires_at, paid_at, paid_payment_id, flag (`late_success`/`amount_mismatch`/`reversed`/`duplicate_payment`/`already_enrolled` — needs staff attention; a later event never replaces a more serious flag) | Single currency per order. Bought for another student (FR-14, setting `separate_payer_enabled`): the student is found or registered by phone (registered only if the order is created; first sign-in still onboards them). Nothing about the student shows in the outcome — their ownership, coupon history and other orders are ignored, and a course they already own is refunded after payment. Someone else's unpaid order never blocks the student; the student sees orders bought for them once paid. Attempts capped per student number and per buyer (`config/yekkola.php` → `checkout`). `payer_msisdn` is kept with the financial record (retention to confirm with counsel). A 100%-discount order is `paid` at creation (no payment, no ledger). |
| `order_items` | id, order_id, course_id, bundle_id, professor_profile_id, granted_at (this order gave access and posted the sale — what a reversal undoes), title_snapshot, list_price_minor, discount_minor, price_minor, discount_funded_by (`platform`/`professor`/`shared`), revenue_share_bps (snapshot: course → professor → platform default), currency | Snapshot = what the ledger uses. Sale split by `discount_funded_by` (provisional): platform (staff coupons only) → professor earns their share of the list price capped at the price paid, `discounts_platform` pays the platform's shortfall; professor → platform keeps its share of the list price; shared (bundles) → both shares on the price paid. A line discounted to 0 earns nobody anything (enrollment source `coupon`). |
| `payments` | id (our reference at the gateway), order_id, idempotency_key (unique per order), gateway, rail (`orange`/`airtel`/`mpesa`), msisdn (encrypted), amount_minor, currency, confirmed_amount_minor, fee_minor, status (`initiated`/`pending`/`succeeded`/`failed`/`expired`/`reversed`), gateway_reference (unique per gateway), operator_reference, failure_code, failure_message, flag, last_result (encrypted), checks, last_checked_at, succeeded_at | Several attempts per order, one in flight at a time. |
| `payment_events` | id, gateway, event_id (unique per gateway), kind (`collection`/`disbursement`), reference (our payment/payout/refund id), payload (encrypted — can carry payer numbers), received_at, processed_at, error, attempts | Webhook inbox (only signature-valid events are stored); processed by `ProcessPaymentEvent` on the `payments` queue, unknown references retried. |
| `refunds` | id, kind (`item`/`unapplied`), order_id, order_item_id, payment_id (collection refunded), amount_minor, currency, status (`requested`/`approved`/`rejected`/`processing`/`completed`/`failed`/`cancelled`), flag (`amount_mismatch`/`paid_twice`/`paid_after_reversal`/`payout_reversed`/`stuck`), reason, requested_by, decided_by, decided_at, decision_note, attempts, failure_code, completed_at | One live refund per course and per unapplied payment. Approval (`finance.refunds`, never on one's own order) revokes access and posts `refund` (sale parts from `SaleSplit` → `refunds_payable`). Every transition locks order → payment → refund and re-checks the payment still stands. A payment reversal rejects requests, cancels refunds with no money out (`refund_reversal`), and turns money out or in flight into a payer debt (`payer_receivable`: by the order reversal for courses, `refund_debt` for unapplied money); a payout that then fails or comes back clears it (`refund_settled`). Payout fees are borne by the platform. The coupon use is not given back. |
| `refund_attempts` | id, refund_id, number, reference (`R-{refund id}-{n}`, unique), gateway, status (`processing`/`succeeded`/`failed`/`reversed`), outcome (`completed`/`overpaid`), confirmed_amount_minor, fee_minor, gateway_reference, operator_reference, failure_code, failure_message, checks, last_checked_at, finished_at | One per payout try, with its own postings: the first success pays the refund (`refund_paid`, confirmed amount); any other success is `refund_overpaid` (payer debt); a reversed payout mirrors its posting (`refund_paid_reversal`). In-flight attempts are re-queried (and re-sent under the same reference if the gateway never registered them, given up after 24 h); failed ones are re-checked hourly for 24 h. Retry (with a reason) re-checks the last attempt first and never runs while one is in flight. |
| `coupons` | id, code (unique, stored upper-case), scope (`platform`/`professor`/`course`), professor_profile_id (null), course_id (null), discount_type (`percent`/`fixed`), value (percent 1–100, or minor units), currency (fixed only), funded_by, max_redemptions, per_user_limit, starts_at, ends_at, is_active, created_by | Only active/end date/limits change after creation. Open orders using a coupon count as reservations; limits re-checked under a row lock at order creation. |
| `coupon_redemptions` | id, coupon_id, user_id (beneficiary), order_id (unique), created_at | Recorded when the order grants access (payment success or free order); deleted when that payment is reversed. A late success past the limit is kept and the order flagged `coupon_over_limit`. |
| `bundles` | id, professor_profile_id (null = platform bundle), slug, title, description, price_minor, currency, status (`draft`/`active`/`archived`) | ≥ 2 paid courses in its currency, priced below their total. Owned courses lower the price pro-rata (provisional); revenue split pro-rata to list prices. No coupon on top. |
| `bundle_courses` | bundle_id, course_id, position | |

### 3.5 Learning

| Table | Columns (key) | Notes |
|---|---|---|
| `enrollments` | id, user_id, course_id, source (`purchase`/`free`/`coupon`/`bundle`/`admin`), order_item_id (null), status (`active`/`revoked`), revoked_reason, revoked_at, enrolled_at, completed_at, progress_pct, last_lesson_uid, last_activity_at | Unique `(user_id, course_id)`. The only access check. |
| `lesson_progress` | id, user_id, course_id, lesson_uid, status (`in_progress`/`completed`), position_seconds, completed_at (server time), client_updated_at, device_id | Unique `(user_id, lesson_uid)`. Largest table — see §7 (no FKs or cascades). Written only by `SyncProgress`'s atomic upsert: furthest position wins, completion never regresses. Video/audio complete at 90% watched, documents when marked done, quizzes only by passing. Every recompute fires `ProgressRecorded` (certificates apply the admin rule); a new live version recomputes all enrollments; a new grant clears `completed_at`. |
| `quiz_attempts` | id, user_id, quiz_id (the version it started on), course_id, lesson_uid, question_order (jsonb), answers (jsonb), started_at, deadline_at (time limit + 30 s grace, server-side; clients get `ends_at`), submitted_at, score_pct, passed, timed_out, device_id | An open attempt is resumed (even across versions) rather than duplicated; `max_attempts` counts every attempt on the lesson uid. Answers after the grace don't count; access is re-checked at submission; a submitted attempt always returns its stored result. Offline attempts (FR-08) with the mobile app. |
| `certificates` | id, serial (unique, public, random `YK-XXXX-XXXX-XXXX`), user_id, course_id, enrollment_id, course_version_id, student_name_snapshot, course_title_snapshot, professor_name_snapshot, issued_at, revoked_at, revoked_reason | Partial unique index: one valid (unrevoked) certificate per `(user_id, course_id)`. Issued by a queued listener on `ProgressRecorded` when the admin rule is met (`LearningSettings`: min completion %, all quizzes passed); waits until the student has a name. Revoked with its enrollment (`RevokeEnrollments`: refund, reversal); a new grant + completion issues a new one. PDF rendered on request from the snapshot in the student's locale, with a QR code to the web verification page (no stored file). Deleted on account deletion; in the data export. |
| `reviews` | id, user_id, course_id, rating (1–5), body, status (`visible`/`hidden`), professor_reply, replied_at, hidden_at, hidden_by, hidden_reason | Unique `(user_id, course_id)`. Needs an active enrollment, progress ≥ `review_min_progress_pct`, and not the author's own course. Published = visible, author not banned, and author still enrolled (a refund or reversal takes the review out; a new grant brings it back). `courses.rating_*` and `professor_profiles.rating_*` are recomputed from published reviews on every review write (professor row, then course row locked) and, queued after commit, on revocation, re-grant, and ban/unban. Public author name is first name + initial. Deleted on account deletion; in the data export. |
| `lesson_threads` | id, course_id, lesson_uid, author_id, title, body, status (`open`/`answered`), reply_count, last_reply_at, answered_at, hidden_at, hidden_by, hidden_reason | Answered when the course's professor replies. Hidden = moderation (visible to its author only). |
| `thread_replies` | id, lesson_thread_id, author_id, body, is_professor, hidden_at, hidden_by, hidden_reason | |
| `wishlist_items` | user_id, course_id, created_at | PK `(user_id, course_id)`. Cleared on account deletion; included in the data export. |

### 3.6 Protection

| Table | Columns (key) | Notes |
|---|---|---|
| `offline_licenses` | id, user_id, device_id, lesson_uid, media_asset_id, rendition, issued_at, expires_at, revoked_at | One per device and lesson (unique), renewed in place; expiry = the _offline license duration_ setting at issue/renewal. Revoked when the device is removed or the account deleted. Drives entitlement sync and audits. |
| `stream_sessions` | id, user_id, lesson_uid, media_asset_id, last_heartbeat_at, ended_at, ended_reason (`replaced`) | Web playbacks only (mobile is limited by registered devices). Live = not ended and a heartbeat within 90 s. Starting a stream locks the user row and ends the oldest live ones over `concurrent_web_streams` (newest wins); a replaced stream's heartbeat gets `playback.stream_replaced`. The limit relies on the player obeying that: signed URLs can't be revoked, so a modified client could keep playing until they expire (`playback_token_ttl_minutes`); no new playlists or keys are issued meanwhile. Pruned after 7 days. |
| `document_stamps` | id, user_id, source_media_id (FK `media`, cascade), status (`pending`/`ready`/`failed`), path (private disk), error | Unique `(user_id, source_media_id)`: one stamped copy per student and source PDF (a replaced PDF gets new copies). Made on the queue (`StampDocument`, FPDI behind `DocumentStamper`): footer with full name, masked phone, date; faint diagonal name + masked phone. Uploads the stamper can't read are refused (`lesson.document_not_supported`), so no student ever gets an unstamped copy. `failed` only for an unreadable PDF; a transient error deletes the row (next request starts over); a `pending` row older than 15 min is queued again. Rows and files erased on account deletion. Signed links stop working once the account isn't active. |

### 3.7 Finance (double-entry ledger)

| Table | Columns (key) | Notes |
|---|---|---|
| `ledger_accounts` | id, type, owner_type (`platform`/`professor`), owner_id (null for platform), currency, unique `(type, owner_type, owner_id, currency)` NULLS NOT DISTINCT | Types: `gateway_clearing`, `gateway_fees` (aggregator collection/disbursement fees), `platform_revenue`, `professor_held`, `professor_available`, `professor_paid_out`, `refunds_payable`, `discounts_platform`, `payer_receivable` (money paid out twice to a payer). |
| `ledger_transactions` | id, type (`sale`/`sale_reversal`/`unapplied_receipt`/`unapplied_receipt_reversal`/`release`/`refund`/`refund_paid`/`refund_reversal`/`refund_debt`/`refund_settled`/`refund_overpaid`/`refund_paid_reversal`/`payout`/`payout_failed`/`adjustment`), reference_type, reference_id, memo, created_by (null), created_at | Unique `(type, reference_type, reference_id)`: a replay can never post twice. Append-only (DB triggers, incl. TRUNCATE). Written only by `PostLedgerTransaction`. |
| `ledger_entries` | id, ledger_transaction_id, ledger_account_id, direction (`debit`/`credit`), amount_minor (> 0, CHECK), currency, created_at | Sum of debits = sum of credits per transaction (enforced in `PostLedgerTransaction`, checked in tests and by `finance:check-ledger` daily: unbalanced, mixed-currency or empty transactions, entries in another currency than their account → critical log, failed run). Append-only (DB trigger). |
| `payout_batches` | id, status (`draft`/`processing`/`completed`/`partially_failed`), currency, created_by (null = scheduled), approved_by, approved_at | One open (`draft`/`processing`) batch per currency (partial unique index). |
| `payouts` | id, payout_batch_id, professor_profile_id, amount_minor, currency, rail, msisdn (encrypted), name, reference (`P-{id}`, unique), status (`pending`/`excluded`/`processing`/`paid`/`failed`), gateway, gateway_reference, operator_reference, fee_minor, failure_code, failure_message, excluded_reason, checks, sent_at, last_checked_at, paid_at | Payout method snapshotted at build. |

Balances (`held`, `available`, `paid_out`) are computed from entries; a cached balance per account may be kept in Redis/materialized view but is never the source of truth.

### 3.8 Moderation, notifications, platform

| Table | Columns (key) | Notes |
|---|---|---|
| `reports` | id, reporter_id, reportable_type (`course`/`review`/`professor`/`thread`/`reply`), reportable_id, reason (`spam`/`abusive`/`inappropriate`/`misleading`/`copyright`/`fraud`/`other`), details, status (`open`/`actioned`/`dismissed`), action (`dismiss`/`hide`/`warn`/`suspend`), handled_by, resolution_note, handled_at | Partial unique index: one `open` report per reporter and item. Resolving closes every open report on the item. |
| `notifications` | Laravel database notifications | In-app feed. |
| `notification_preferences` | user_id, type, channel (`push`/`sms`/`email`/`in_app`), enabled | Defaults in code; rows only for overrides. |
| `settings` | spatie/laravel-settings | Groups: commerce, payouts, protection, catalog, learning, moderation. Defaults in `database/settings/`. |
| `activity_log` | spatie/laravel-activitylog (ULID id; subject/causer ids are strings) | All admin actions (`RecordAudit`) + settings changes (before/after, automatic). |
| `webhook_events` | id, provider, event_id (unique per provider), type, payload (jsonb), received_at, processed_at, error | Generic provider inbox (payments use `payment_events`). Unused since the Mux driver was removed: R2 uploads are confirmed by the studio, not by webhook. |
| `course_daily_stats` | course_id, date (unique together), enrollments, sales (order items by `granted_at`, reversed payments excluded), revenue_minor (jsonb per currency: what students paid for those sales, before refunds), completions, active_students, reviews, quiz_attempts, quiz_passes | `stats:rollup-courses` hourly recomputes today and yesterday (idempotent; a day recomputed to nothing loses its row; `--days=N` backfills); feeds professor analytics. Page views need view tracking first (TODO). |
| `course_activity_days` | course_id, date, user_id (primary key together; index date, course_id) | Append-only, written by each progress sync (`ON CONFLICT DO NOTHING`): who studied a course on a day. Daily and period active-student counts (distinct) come from here, not from `lesson_progress`, whose timestamps are overwritten. No foreign keys (cheap writes, partition by date later). |
| `platform_daily_stats` | date (PK), signups, enrollments, orders_created, orders_paid, courses_published, gmv_minor, net_revenue_minor, refunds_minor, payouts_minor (jsonb per currency) | Admin dashboard. Recomputed from source tables by `stats:rollup-platform` (hourly: today + yesterday; `--days` backfills); idempotent upsert. Active students are counted live from `enrollments.last_activity_at` (indexed). |

---

## 4. API

### 4.1 Conventions

- Base: `https://api.yekkola.<tld>/api/v1`. JSON only. Versioned by path; breaking changes → `/v2`.
- Auth: web/admin use Sanctum SPA cookies (`GET /sanctum/csrf-cookie` first; `X-XSRF-TOKEN`); mobile uses `Authorization: Bearer <token>` + `X-Device-Id`.
- Locale: `Accept-Language: fr|en` → messages, validation errors, translatable fields.
- IDs: ULIDs; public pages use slugs.
- Lists: `?page=&per_page=` (offset, per_page 1–100, default 25) for admin tables; `?cursor=` for infinite feeds and large tables. Filters `?filter[status]=…`, sorting `?sort=-created_at`, includes `?include=professor`.
- Envelope: `{ "data": …, "meta": { pagination… }, "links": {…} }`.
- Errors: RFC 9457-style `{ "type", "title", "status", "detail", "code", "errors": { field: [msg] } }` with a stable machine `code` (e.g. `otp.expired`, `enrollment.required`, `device.limit_reached`). `title` is translated per `Accept-Language` and shown to people as is (web: `ApiError.title`); clients branch on `code`, never on text.
- Idempotency: `Idempotency-Key` header required on `POST /orders`, `POST /orders/{id}/payments`, payout approvals; replays return the original response.
- Rate limits (per user/IP/phone): OTP request 3/10 min per phone, 10/h per IP, 60 s cooldown, global hourly SMS budget; OTP verify 5 attempts per challenge and 15 wrong codes per number per day, plus 30/10 min per IP; profile updates 10/min per user; data export 1/day; payments 10/h per user; account deletion 10/h; impersonation starts 20/h and staff management 60/h per staff member; identity and credential files 30/min and 500/day per staff member; CSV and export downloads 10/min. Every route also has a baseline (limiter `api`, `throttleApi()` in `bootstrap/app.php`): 600/min per signed-in account (cookie or bearer token, also on public routes), 1,500/min per visitor otherwise (provider webhooks and our web server's own renders excluded). Requests rejected by `auth:sanctum` (401) are not counted. Per-visitor limits rely on the real client IP (trusted proxies on Laravel Cloud, TODO 1.2). Limits use atomic increments (hit, then compare).
- Webhooks: signature-verified, deduplicated by event ID, acknowledged fast (`2xx`), processed on the queue.

### 4.2 Endpoint catalogue

**Auth & account** (`/auth`, `/me`)

| Method | Path | Purpose |
|---|---|---|
| POST | /auth/otp/request | Send OTP to phone |
| POST | /auth/staff/sign-in | Staff only, browsers only: email + password + bot token → session (`throttle:staff-sign-in`); same `auth.invalid_credentials` for every failure |
| POST | /admin/users/{user}/impersonate | Super admin, browser session: become that account for up to 60 min (never a super admin or banned); returns `home` + `redirect_to`; audited |
| POST | /me/impersonation/stop | Back to the super admin; returns `redirect_to` (admin accounts list) |
| PUT | /me/password | Staff: set one's own password (`current_password` when one exists, else a recent sign-in); other sessions end |
| PUT | /admin/staff/{user}/password | Staff manager sets a staff member's password; they must change it (`must_change_password`; admin routes answer `auth.password_change_required` until then) |
| POST | /auth/otp/verify | Verify OTP → `{user, is_new_user, token, device}`: session cookie on web (`token`/`device` null), token on mobile (with device payload) |
| POST | /auth/logout | End session / revoke current token |
| GET / PATCH | /me | Profile (name, email, locale, province); GET also says `is_staff` and lists `permissions`, so the back office hides what the API would refuse |
| POST | /me/phone/otp | Send a code to the new number |
| PUT | /me/phone | Confirm the new number (signs out other sessions/devices) |
| GET | /me/devices | List registered devices |
| DELETE | /me/devices/{id} | Remove device (revokes token + offline licenses) |
| POST / GET | /me/export | Request a data export / latest export status |
| GET | /me/export/download | Download the latest ready export |
| DELETE | /me | Request account deletion (14-day grace) |
| DELETE | /me/deletion | Cancel a pending deletion |

**Public**

| Method | Path | Purpose |
|---|---|---|
| GET | /config | Public settings: currencies, locales, feature flags, min app version |
| GET | /provinces | The 26 provinces (profile form) |
| GET | /categories | Visible category tree (request locale) with listed-course counts |
| GET | /branding | Brand image URLs (`logo_url`, `footer_logo_url`, `favicon_url`, `share_image_url`; null = the app's built-in one). The web app caches it with the tag `branding` |
| GET | /courses | Catalogue + search (`q`, category slug incl. subcategories, price `free`/`paid`, currency, language, level, audience, sort `relevance`/`popular`/`newest`/`rating`/`price_asc`/`price_desc`). Meilisearch ranks `q`; filters and sorts run in Postgres; database match if search is down |
| GET | /courses/{slug} | Course detail (live version, curriculum outline with preview flags and per-lesson download size, professor block) |
| POST | /lessons/{uid}/preview-playback | Preview lessons of listed courses without signing in (no watermark, no stream session) |
| GET | /courses/{slug}/reviews | Published reviews (`page`, `per_page`, `filter[rating]`, `sort=-created_at\|rating`) |
| GET | /bundles | Bundles on sale (slug, title, updated_at; paginated, sitemap) |
| GET | /professors/{slug} | Professor profile + courses; `rating` is null below 3 reviews (as courses) |
| GET | /certificates/{serial} | Certificate verification: names as issued, `valid`/`revoked` (throttled) |
| GET | /featured | Home page rails `{featured, popular, new, free}` (course cards) |

**Student**

| Method | Path | Purpose |
|---|---|---|
| GET | /me/enrollments | My courses with progress (`page`, `per_page`, `filter[state]=in_progress\|completed`, `sort`) |
| POST | /courses/{id}/enroll | Enroll in a free course |
| GET | /me/courses/{id} | Learning view: live curriculum, my status per lesson, continue/next lesson, quiz facts (never answers), per video/audio lesson `downloads` (renditions with real sizes, lowest first), my certificate, `has_reviewed` |
| POST | /lessons/{uid}/playback | `playlist_url` (API master playlist, with the web stream session), `poster_url`, `expires_at`, watermark text (short name + masked phone) + web `stream.session_id`. Enrolled, or a preview lesson. Never a media file URL |
| GET | /lessons/{uid}/hls/master.m3u8, /lessons/{uid}/hls/{rendition}.m3u8 | Public route, optional auth (cookie or token), `?session=`: playlists after the access checks (enrollment or preview, active account, live web stream session or device token); segment URIs are pre-signed R2 URLs, the key URI is the key endpoint. `playback.stream_required` (409) without a live session |
| GET | /lessons/{uid}/hls/key | Same checks; the asset's 16-byte AES-128 key, `no-store` |
| POST | /lessons/{uid}/playback/heartbeat | `{session_id}` every 30 s; 409 `playback.stream_replaced` → stop (another screen took over) |
| POST | /lessons/{uid}/offline-license | `{rendition?}` (default: our lowest). Mobile token + matching `X-Device-Id` only (`offline.device_required` 403 otherwise), active enrollment, video/audio. → `{license_id, lesson_uid, media_id, kind, rendition, renditions[], playlist, segments_expire_at, key (base64), expires_at, watermark}`. Asking again renews |
| GET | /lessons/{uid}/document | My stamped copy: `ready` + signed `url` (10 min), or 202 `processing` with `retry_after`. The URL (`GET /documents/{stamp}/download`, signed, no session) streams the PDF inline, `no-store` |
| GET | /me/entitlements | Mobile token + matching `X-Device-Id`. This device's offline licenses → `{checked_at, licenses: [{license_id, lesson_uid, media_id, current_media_id, rendition, expires_at, action: keep\|renew\|delete, reason}]}`; reasons: `expired`, `expiring`, `revoked`, `no_access`, `media_changed`, `media_unavailable`. Read-only |
| PUT | /me/progress | Batch progress sync `{items: [{lesson_uid, position_seconds?, completed?, client_updated_at?}]}` (≤ 100; monotonic) |
| GET / POST | /lessons/{uid}/quiz-attempts | My attempts / start (or resume) one: questions without answers |
| PUT | /quiz-attempts/{id} | Submit `{answers: {question_id: [option ids]}}` → score, corrections, explanations |
| GET | /me/certificates | My certificates (with verification link); `GET /me/certificates/{id}/pdf` in my locale |
| GET / POST / PATCH / DELETE | /courses/{id}/review | My review `{rating, body?}` (POST once; then PATCH) |
| GET / POST | /lessons/{uid}/threads | Lesson Q&A (enrolled students and the course's professor; `qa.access_required` otherwise): latest activity first; ask `{title, body}` (throttled 6/min, 100/day). Hidden posts only for their author; banned authors' posts left out |
| GET / POST | /threads/{id}, /threads/{id}/replies | One question with its replies; reply `{body}` — the professor's reply is marked and answers the question; no replies on a hidden question |
| POST | /reports | Report a listed course, a visible review or a public professor `{type, id, reason, details?}` (details required for `other`); not one's own content (422 `report.own_content`); an open report by the same person on the same item is returned unchanged; throttled 5/min, 30/day |
| GET / POST / DELETE | /me/wishlist[/{courseId}] | Wishlist (`POST {course_id}`, idempotent, listed courses only) |
| GET | /me/notifications, /me/notifications/unread-count; POST /me/notifications/read | In-app feed (PRD-10 FR-03), per app (`filter[app]` `web` default / `admin` = staff alerts): newest first (`filter[unread]`), title/body rendered in the request language from the stored key + params, `link` = web path; mark read `{ids?}` (all when omitted) |
| GET / PUT | /me/notification-preferences | Per event × channel for events users may turn off (`course_updated`, `question_answered`, `certificate_issued`, `new_question`, `new_review`, `new_sale`); channels: in_app, email, push; critical events and SMS refused (422). Push via `PushSender` (FCM; log driver in dev) to active devices with a token, optional events capped at 5/day; sales, reviews and questions are emailed once a day (`notifications:send-digest`). Staff alerts (`StaffAlerts`: ledger check, partially failed payout batch, payments pending > 30 min) go to staff with the matching permission, at most once per 6 h each |

**Commerce**

| Method | Path | Purpose |
|---|---|---|
| GET | /checkout/rails?currency= | Operators and whether each can take a payment now (public) |
| POST | /checkout/quote | Price quote `{course_ids | bundle_id, coupon_code?}` (20/min per user); `can_buy_for_others` reflects the "separate payer" setting |
| GET | /bundles/{slug} | Active bundle whose courses are all listed |
| POST | /orders | Create order `{course_ids | bundle_id, coupon_code?, beneficiary_phone?, payer_msisdn?}` (`Idempotency-Key`) |
| POST | /guest/orders | Parent without an account: `{…, beneficiary_phone, payer_msisdn?, bot_token}` → order + one-time `access_token` (10/h per IP) |
| GET | /guest/orders/{id} | Guest order status (`X-Order-Token`) |
| POST | /guest/orders/{id}/payments | Guest payment (`X-Order-Token`, `Idempotency-Key`) |
| POST | /orders/{id}/payments | Start payment `{rail, msisdn?}` (`Idempotency-Key`; 10/h per user; msisdn defaults to the account's) |
| GET | /orders/{id} | Order + payments (polling; re-checks an in-flight payment at most every 10 s) |
| POST | /orders/{id}/cancel | Cancel unpaid order |
| GET | /me/orders | Order history: orders I bought and orders bought for me (only the buyer can pay) |
| GET | /orders/{id}/receipt[.pdf] | Receipt as JSON or PDF (request language) for buyer and student; guests: `GET /guest/orders/{id}/receipt.pdf` |
| POST | /orders/{id}/refund-requests | Request a refund `{order_item_id, reason}` (window and progress limits from settings). Order items carry `refund {status, can_request}` from the same checks |
| GET | /me/refunds | My refund requests |

**Professor** (`/professor`, role: professor except application endpoints)

| Method | Path | Purpose |
|---|---|---|
| GET / PUT | /professor/application | Application status / create or autosave draft (any signed-in user); includes `missing` (required fields still empty) and `reapply_at` (after a rejection) |
| POST | /professor/application/submit | Submit with `accept_terms` |
| POST / DELETE | /professor/application/credentials[/{file}] | Credential files (multipart, max 5) |
| GET / PATCH | /professor/profile | Own profile (incl. onboarding + terms status) |
| POST | /professor/profile/avatar | Profile photo (multipart) |
| POST | /professor/terms/accept | Accept the current professor agreement |
| GET / POST | /professor/kyc | Latest identity check / submit (multipart front, back, selfie) |
| POST / PUT | /professor/payout-method[/otp] | Code to own number / set rail + number (48 h hold on change) |
| GET / POST / PATCH {id} | /professor/coupons | Own professor-funded coupons (own courses); staff coupons are not visible here |
| GET / POST / PATCH {id} | /professor/bundles | Own bundles (own courses) |
| GET | /professor/authoring-options | Choices and limits for the studio (enabled currencies + price limits/steps, preview limit, upload sizes, default pass mark, languages, levels, audiences) |
| GET / POST | /professor/courses | List (`filter[status]`, paging) / create (creates draft v1) |
| GET / PATCH / DELETE | /professor/courses/{id} | Settings (category, language, level, audience, price/free — immediate) / delete if never published. Includes `can` (start_draft, submit, withdraw, unpublish, republish, archive, delete) from the actions' own rules, so the studio offers only what applies |
| POST | /professor/courses/{id}/draft | Start a draft from the live version |
| POST | /professor/courses/{id}/(submit\|withdraw) | Review submission |
| POST | /professor/courses/{id}/(unpublish\|republish\|archive) | Visibility |
| GET / PATCH | /professor/courses/{id}/draft | Draft with curriculum + submit checklist / details |
| POST | /professor/courses/{id}/draft/cover | Cover image (16:9, ≥ 1280×720) |
| POST / PATCH / DELETE | /professor/courses/{id}/draft/sections[/{sid}] | Sections |
| POST / PATCH / DELETE | /professor/courses/{id}/draft/lessons[/{lid}] | Lessons |
| PUT | /professor/courses/{id}/draft/order | Full order of sections + lessons |
| POST | /professor/courses/{id}/draft/lessons/{lid}/media | Start a video/audio upload `{size_bytes, content_type}`: new asset + R2 multipart upload; returns `part_size_bytes`, `part_count` and the first 20 `parts` (`{number, url}`) |
| POST | /professor/media/{id}/parts | Fresh pre-signed URLs for up to 100 parts of an open upload |
| POST | /professor/media/{id}/complete | Confirm with every part's ETag: assembles the object and queues processing (`processing`); safe to repeat |
| POST | /professor/courses/{id}/draft/lessons/{lid}/document | PDF (multipart) |
| PUT | /professor/courses/{id}/draft/lessons/{lid}/quiz | Whole quiz (settings + questions + options) |
| GET | /professor/media/{id} | Media processing status |
| GET | /professor/analytics/overview | `?days=7\|30\|90\|365` (default 30): totals, the previous period's totals, a daily series and per-course figures, from `course_daily_stats` (courses owned now; earnings stay on `/professor/earnings`) |
| GET | /professor/analytics/courses/{id} | One own course: the same figures + funnel of its active enrollments (enrolled → started → half-way → completed) |
| GET | /professor/earnings | Balances per currency (held/available from the ledger, paid out from payouts), monthly earnings (12 months), next payout run |
| GET | /professor/ledger, /professor/ledger.csv | Statement lines (one per entry on the professor's accounts; `type`, `from`, `to`), or as CSV |
| GET | /professor/payouts | Payout history |
| GET | /professor/reviews | Reviews of my courses (`filter[course_id\|rating\|replied]`); `POST /professor/reviews/{id}/reply {reply\|null}` |
| GET | /professor/threads | Q&A inbox: my courses' questions, `filter[status]` `open` (default)/`answered`/`all`, oldest first, with course and lesson titles |

**Admin** (`/admin`, role: admin/moderator; finance endpoints need `finance.*`)

| Method | Path | Purpose |
|---|---|---|
| GET | /admin/dashboard | KPIs + charts |
| GET / PATCH | /admin/users[/{id}] | Search, view, suspend/ban/reactivate |
| POST | /admin/users/{id}/enrollments | Grant access `{course_id, note}` (`users.manage`): source `admin`, no order/payment/ledger; live, not taken-down course; `enrollment.already_active` (409); audited `enrollment.granted` |
| GET | /admin/professor-applications[/{id}] | Queue (`moderation.review`; `filter[status]`, `filter[subject]`, `sort`); each item has `can` (start_review, decide) for the signed-in staff member |
| POST | /admin/professor-applications/{id}/(start-review\|approve\|reject\|request-info) | Decide (note required except approve) |
| GET | /admin/professor-applications/{id}/credentials/{file} | Stream a credential file (audited) |
| GET | /admin/kyc-submissions[/{id}] | Identity checks (`kyc.view`) |
| GET | /admin/kyc-submissions/{id}/files/{front\|back\|selfie} | Stream an ID image (audited) |
| POST | /admin/kyc-submissions/{id}/(verify\|reject) | Decide (`kyc.review`) |
| GET / PATCH | /admin/professors[/{id}] | List/search; revenue-share override with note (`professors.manage`) |
| POST | /admin/professors/{id}/(suspend\|reinstate) | With reason |
| GET | /admin/course-versions[/{id}] | Review queue (`?filter[state]=in_review`, `sort`); detail with curriculum + changes since live and `can` (approve, reject) |
| POST | /admin/course-versions/{id}/(approve\|reject) | Decide (note required to reject) |
| GET | /admin/course-versions/{id}/lessons/{lessonId}/(preview\|document) | Reviewer preview (`moderation.review`): `{kind, playlist_url, poster_url, document_url}`; the original PDF inline (audited `lesson.document_previewed`) |
| GET | /admin/media/{assetId}/hls/(master.m3u8\|{rendition}.m3u8\|key) | Staff HLS for previews (`moderation.review`, staff session on each request; signed segment URLs; no stream limit or viewer watermark) |
| GET / PUT | /admin/featured-courses | Featured rail: full ordered list of listed course ids (`catalog.manage`, audited) |
| GET / PATCH | /admin/courses[/{id}] | Unpublish, takedown |
| CRUD | /admin/categories (+ `PUT /admin/categories/order`) | Categories |
| GET / POST | /admin/reports, /admin/reports/{id}/resolve | Reports queue (`moderation.reports`): open first by default (`filter[status]` `open`/`actioned`/`dismissed`/`all`, `type`, `reason`, `target_id`), each with the item as it is now and how many reports are open on it. Resolve `{action, note}` closes every open report on the item: `dismiss` (note optional), `warn` (audited `user.warned` on the author), `hide` (review hidden; course taken down — needs `catalog.manage`), `suspend` (review author's account — `users.manage`; course/profile professor — `professors.manage`). `can` per report; 409 `report.already_resolved`; audited `report.resolved` |
| GET / POST | /admin/threads, /admin/threads/{id}/(hide\|restore), /admin/thread-replies/{id}/(hide\|restore) | Q&A moderation (`moderation.reports`): every question with all replies (`filter[hidden]`, `course_id`, `q`); hide/restore with a reason, audited (`thread.hidden`, `thread_reply.hidden`…) |
| GET | /admin/orders[/{id}], /admin/payments[/{id}] | Commerce views |
| POST | /admin/payments/{id}/recheck | Force status re-query |
| GET | /admin/refunds[/{id}]; POST /admin/refunds/{id}/(approve\|reject\|retry) | Refund queue with policy facts and flags (`finance.refunds`, audited; reject and retry need a note) |
| POST | /admin/payments/{id}/refund | Send back a payment's unapplied money (`finance.refunds`) |
| GET / POST / GET {id} / PATCH {id} | /admin/coupons | All coupons with paid uses and the professor/course they apply to (filters: code, scope, active); new coupons are platform-funded; PATCH changes only active, end date and limits (`promotions.manage`, audited) |
| GET | /admin/coupons/options | Coupon form choices: enabled currencies (default first) and default (`promotions.manage`) |
| GET / POST / PATCH {id} | /admin/bundles | Platform and professor bundles with their owner (filters: title, status, currency); PATCH takes any field, and status alone never re-validates prices (`promotions.manage`, audited) |
| GET | /admin/users, /admin/users/{id} | Accounts (`users.view`): search by phone or name, filter status/role; detail with devices, recent enrollments and orders, `can` |
| POST | /admin/users/{id}/status, /admin/users/{id}/sign-out | Suspend / ban / reactivate `{status, note}` (suspend or ban signs out everywhere; a ban recounts the ratings of courses they reviewed), sign out everywhere (`users.manage`; never one's own account; staff accounts need `staff.manage`; audited) |
| DELETE | /admin/users/{id}/devices/{device} | Remove a device (`users.manage`, audited) |
| GET | /admin/orders, /admin/orders/{id} | Orders (`commerce.view`): filters status, number, phone (student or buyer), flag, currency, `stuck` (a payment waiting > 10 min); detail with items + access, payment attempts + gateway events, refunds, ledger transactions |
| POST | /admin/payments/{id}/recheck | Ask the gateway again about a payment (audited); returns the order detail |
| GET | /admin/ledger/accounts, /admin/ledger/transactions | Ledger explorer (`finance.view`): accounts with debits/credits/balance and the professor's name (filters: type, currency, owner); transactions with entries (filters: type, reference, account, dates) |
| POST | /admin/ledger/adjustments | Manual adjustment `{professor_profile_id, currency, amount_minor, direction, label, reason}`: platform revenue ↔ professor available (`finance.adjustments`, `Idempotency-Key`, reason audited, label on the statement) |
| GET / POST | /admin/payout-batches | List / build |
| GET | /admin/payout-batches/{id} | Batch with its lines |
| POST | /admin/payout-batches/{id}/approve | Approve and send (`Idempotency-Key`) |
| POST | /admin/payouts/{id}/exclude | Leave a line out of a draft batch `{note}` |
| GET / PUT | /admin/settings | Platform settings (`settings.manage`): all six groups; PUT takes any groups, each whole. `UpdateSettings` checks rules across settings on the would-be result (default currency enabled; price limits and payout minimum for every enabled currency; earnings hold ≥ refund window), then saves in one transaction; `AuditSettingsChange` logs before/after |
| POST / DELETE | /admin/settings/branding/{asset} | Brand images (`settings.manage`; `asset` = `logo`, `footer_logo`, `favicon`, `share_image`): multipart `file` (no SVG; logos PNG/WebP/JPEG ≤ 1 MB; favicon square PNG 48–1024 px ≤ 512 KB; share image ≥ 1200×630 ≤ 5 MB) stored on the public disk under a new name; DELETE goes back to the built-in one. Saved in the `branding` settings group (audited), old file deleted, web pages refreshed (`branding` tag) |
| GET | /admin/dashboard | Back-office home (any staff): 30-day KPIs vs the 30 before, active students 7/30 d, daily series, queues (applications and course reviews with overdue vs SLA, KYC, open reports, refunds, stuck payments, failed payouts). Money only with `commerce.view`/`finance.view`; each queue only with its permission |
| GET / POST | /admin/courses, /admin/courses/{id}/(unpublish\|republish\|take-down\|restore) | Courses (`catalog.view` to list, `catalog.manage` to act; reason `note` required, audited). Unpublish: off the catalogue, enrolled keep access; take down: closed to everyone |
| POST | /admin/courses/{id}/transfer | Move to another active professor `{professor_profile_id, note}` (super admin): future sales only (order items snapshot the owner); the old professor's coupons on it are switched off, it leaves their bundles (active ones are archived), media used only by this course moves too; audited `course.transferred` |
| GET / POST / PATCH / DELETE / PUT | /admin/categories[/{id}], /admin/categories/order | Category tree (`catalog.manage`): all categories with fr/en names, visibility, counts; two levels at most; delete only when unused (409 `category.in_use`); order one level `{parent_id, ids}`; audited; saving refreshes the public pages |
| GET / POST | /admin/reviews, /admin/reviews/{id}/(hide\|restore) | Review moderation (`moderation.reports`): hide/restore with a reason; ratings recounted under the professor → course lock; course and professor pages refreshed; audited |
| GET | /admin/audit-log | Audit log (`audit.view`): newest first; filters event (or family, e.g. `refund`), causer, subject, dates; entries carry causer, subject, details (before/after, reason) and IP |
| GET / POST / PUT / DELETE | /admin/staff[/{user}], /admin/staff/permissions | Staff (`staff.manage`): list; invite by phone `{phone, role, permissions}` (account created if needed); change role + extra permissions; end access (staff roles and extras removed, signed out everywhere). Never one's own access; anything touching a super admin needs `staff.roles`; audited (`staff.updated`, `staff.removed`) |
| CRUD | /admin/staff | Staff accounts + roles |

**Webhooks** (no auth; signature-verified)

| Method | Path |
|---|---|
| PUT | /_fake/video-uploads/{upload} | Fake video driver only: stands in for the upload URL (local, staging); accepts chunked PUTs (`Content-Range`), completes on the last; exempt from CSRF |
| POST | /webhooks/payments/{gateway} | Payment gateway (`fake` until the aggregator is integrated) |
| POST | /webhooks/sms/{provider} (delivery reports) |

The OpenAPI spec generated from code is authoritative; this table is the design intent.

---

## 5. Web UI architecture

### 5.1 Layers

```
app/ (routes, layouts, data loading)           ← thin: compose features, read URL params
  └── features/<domain>/ (components, hooks, schemas)   ← product UI per domain
        └── packages/api-client (generated hooks + Zod)  ← all server access
        └── packages/ui (shadcn primitives + blocks)     ← no domain knowledge
              └── theme tokens (Yekkola brand)
```

Rules:
- `packages/ui` never imports from apps or `api-client`. Blocks are generic (e.g. `DataTable<T>`).
- Components never call `fetch` directly — only generated hooks (client) or generated fetchers (server components).
- Server state = TanStack Query. URL state (filters, sort, page, tabs) = nuqs. Local UI state = React state. No global store unless a real need appears (the video player may use a small Zustand store).
- Forms = React Hook Form + Zod schemas from `api-client`; server validation errors map onto fields by name.
- Money, dates, phone numbers are formatted only via `packages/i18n` formatters.

### 5.2 apps/web

- **Public (marketing) routes:** React Server Components, fetched server-side with ISR (`revalidate` per page type, on-demand revalidation via tag when a course is published). Minimal client JS. Structured data (JSON-LD `Course`, `Person`) and localized metadata for SEO.
- **Student area:** client components behind an auth guard (`/me` query). Learning page: `ProtectedPlayer` (hls.js on a native video/audio element; credentials only to the API's playlist/key URLs) + `WatermarkOverlay` + curriculum sidebar + Q&A tab; progress posted on intervals and on pause/end.
- **Checkout:** order → rail picker → number input → "approve on your phone" waiting screen polling `GET /orders/{id}` with backoff → success/failure states with retry.
- **Professor studio:** dashboard (KPI cards + charts), course list (DataTable), course editor (tabs: details, curriculum drag-and-drop, pricing, review status), media uploader (chunked upload to pre-signed R2 URLs with progress + resume, then upload-complete), quiz builder, earnings/payouts tables, reviews & Q&A inbox.

### 5.3 apps/admin

- Shell: sidebar navigation by domain, command palette (search users/courses/orders by ID or phone), breadcrumb, locale switch.
- Every list page = `DataTable` with server pagination/sort/filter, URL-synced, column visibility, row selection, bulk actions, CSV export (server-generated for large sets).
- Detail pages: header (status badge + primary actions) + tabs (overview, related records, activity log).
- Review screens: side-by-side course version diff (what changed vs live), lesson preview with player, approve/reject with required notes.
- Dashboards: KPI cards (GMV, net revenue, new students, active students, conversion), time-series charts, queues needing attention (pending applications, versions in review, open reports, stuck payments, failed payouts).
- Destructive or financial actions always go through `ConfirmDialog` with a typed reason; reasons are stored in the audit log.

### 5.3a Shared front-end plumbing

- **Workspace:** pnpm + Turborepo at the root; packages ship TypeScript source, consumed via `transpilePackages`. CI: `web.yml` (Prettier, ESLint, `tsc`, Vitest, `next build`, `pnpm audit`) and `contract.yml` (regenerates `packages/api-client` from `api/openapi.json`, fails on drift).
- **HTTP:** one hand-written Orval mutator (`packages/api-client/src/fetcher.ts`): `/api/v1` prefix, `Accept-Language` from the current locale, Sanctum cookies with `X-XSRF-TOKEN` on unsafe methods (fetched once from `/sanctum/csrf-cookie`), non-2xx thrown as `ApiError` with the error envelope.
- **Public page caching:** Server Components fetch through `apps/web/src/lib/public-api.ts` with ISR (`revalidate` 5 min) and cache tags (`courses`, `categories`, `course:{slug}`, `professor:{slug}`). When a public course or professor changes, the API queues `RevalidateWebPages`, which POSTs the tags to `apps/web /api/revalidate` (HMAC with `WEB_REVALIDATE_SECRET`); the web app expires them at once (`revalidateTag(tag, { expire: 0 })`). Free-text searches and certificate checks are never cached.
- **Rate limits behind SSR:** the web server sends `X-Web-Server-Key` (`WEB_SERVER_KEY`) and, on per-visitor requests, the visitor's IP in `X-Client-Ip`; the API's public limiters key on that IP (`ClientIp`), and the server's own cache refreshes share one larger bucket.
- **Locales:** next-intl, `localePrefix: "as-needed"` (French at `/`, English at `/en`), catalogues from `packages/i18n/messages`, keys typed against `fr.json`.

### 5.4 Design system

- Tokens (colors, radius, typography, spacing) defined once in `packages/ui` as CSS variables; light + dark themes; brand assets (logo, wordmark, favicon) live there. Full spec: [`brand.md`](brand.md).
- Typography: **Nunito** everywhere, via `next/font/google` (latin + latin-ext, variable, `display: swap`); bundled as assets in Flutter.
- Brand colors: primary `#2c3892` → shadcn `--primary` (foreground white); secondary `#fdb73b` → `--brand-accent` (foreground indigo). shadcn `--secondary` stays neutral. Contrast rules in [`project-context.md`](../project-context.md#brand). Generate a full 50–950 scale for each brand color for hover/active/subtle states.
- Charts: primary and amber are series 1 and 2; further series from a validated categorical palette that stays distinguishable alongside them (light and dark).
- Status colors and labels for every enum (`StatusBadge` map) — one source, used by both apps.
- Mobile-first breakpoints for apps/web (most traffic is phones); admin optimized for desktop but usable on tablet.

---

## 6. Mobile architecture

- **Layers:** `presentation` (widgets, Riverpod notifiers) → `domain` (entities, use cases) → `data` (API via generated client, drift DAOs).
- **Offline-first:** drift is the source of truth for the UI; the network refreshes it. Writes (progress, quiz attempts, reviews drafted offline) go to an outbox table and are flushed by a sync engine when connectivity returns (with exponential backoff).
  - *Built (`lib/core/db`, `lib/core/sync`):* schema v2; tables `outbox` (per account; one row per kind + key, later writes merged in, e.g. one progress row per lesson), `lesson_progress` (this phone's monotonic view, for resuming offline) and `sync_state`. `SyncEngine` sends due rows per kind in batches (progress: 100 per `PUT /me/progress`): accepted → removed, skipped → dropped, network/5xx/429 and client errors → retried with backoff (5 s doubling to 15 min, ±20 % jitter; a save during backoff keeps waiting), 422 → only the items it names (`items.<n>`) dropped, other 4xx → batch dropped, 401/403 → kept for the account. A write merged in while its batch is in flight stays queued (guarded by a `revision` column); a run stops if the account changes. Timestamps are sent with millisecond precision (the API rule). Only the signed-in account's rows go out. `SyncScheduler` runs it at start, on reconnect, on resume, every minute, after sign-in and 3 s after a write. The offline player saves progress every 15 s, on pause and on close, and resumes from it.
- **Catalogue offline (`lib/features/catalog`, `lib/core/cache`):** read-only API answers (featured shelves, categories, course and professor pages, the first page of each search) are kept in the drift table `cached_responses` (key → JSON, fetched/used times). Copies are per UI language (names come translated). Screens read the copy at once, then refresh from the network (not again within 10 min, unless the user pulls to refresh); offline they show the copy with a "saved on …" notice (another failure: "couldn't update"), or a clear message when there is none. A 404/410 drops the copy (taken-down courses don't linger). A good answer is shown even if it can't be saved. At most 300 copies, none older than 30 days (least recently read dropped first). Covers and avatars go through `cached_network_image` with the decode width capped at the display size. Search loads more pages only once page 1 is final, and after a failed page waits for a tap on retry. Signed-in tabs: Explore (landing) and My courses.
- **Checkout (`lib/features/checkout`):** the course page's button reads the account's enrolments (`GET /me/enrollments`, kept per account in `cached_responses`): open, one-tap free enrol, or buy. Checkout shows the API's quote (coupon, owned courses left out), the operators `GET /checkout/rails` says are available, and the payer number (default: the account's). `POST /orders` and `POST /orders/{id}/payments` carry an `Idempotency-Key` stored in `sync_state` (at most 24 h, the API's result lifetime) until the API's answer is final (2xx, 400/404/410/422), so retries and restarts never repeat a payment request; the selection body sends only `course_ids` (the generated model would also send `bundle_id`). Orders: the course's open order is remembered on the phone (before its key is dropped) and reused while the API says it is open — it keeps its price, a new coupon doesn't apply; the API refuses a second open order (`order.open_order_exists`) and that order is resumed; a key that brings back an order which can no longer be paid is replaced. Whether an order can still be paid is the API's status, never the phone's clock; the countdown uses the server's clock (`Date` header, `ServerClock`). The waiting screen polls `GET /orders/{id}` (2 s → 8 s backoff, stopped in the background, immediate on return), shows success only once the API says `paid` (a refunded order says so), and after a failed attempt shows the API's translated reason and retries on the same order; a payment request that failed for a non-final reason re-reads the order.
- **Learning (`lib/features/learning`):** My courses (`GET /me/enrollments`) and the course view (`GET /me/courses/{id}`) are kept per account in `cached_responses` (readable offline). A video/audio lesson plays from its download when this account has a complete one, else streams: `POST /lessons/{uid}/playback`, then `HlsStreamProxy` fetches the master and media playlists and the key with the app's credentials (only from the API's host) and serves them to `video_player` on loopback; segment URIs stay the signed R2 URLs, fetched without our headers. The key is held in memory and wiped on close. Playlists and the key are fetched without following redirects; an API refusal keeps its code and translated message (`proxy.lastError`); the key is fetched once (concurrent requests share it) and must be 16 bytes. A failure after the lesson started (expired segment links, access ended) stops playback, saves the position and offers a retry, which asks for playback again. Both players share `LessonVideoView` (overlay, play/pause, 10 s back, speed 0.75–2×, buffering) and `ProgressTracker` (resume at the furthest of the phone's and the API's position, save every 15 s / pause / close); `FLAG_SECURE` on both. Back from a lesson, the outbox is flushed and the course view refreshed. A download whose license expired streams instead. Documents and quizzes are not in the app yet.
- **Entitlement sync:** `EntitlementSync` (`lib/features/media/data`), run by `SyncScheduler` on start, reconnect, resume, sign-in and the periodic timer (throttled to 30 min — a stored check time in the future counts as stale —, skipped without downloads or for a lesson still downloading; a network failure during renewals leaves the check unmarked so it runs again): follows the API's `keep`/`renew`/`delete` per license (§1.3 E step 3).
- **Downloads:** our own HLS downloader fetches one rendition's encrypted segments (resumable) into app-private storage (`downloads/<lesson>/`: `manifest.json`, `playlist.m3u8` template, `seg/<n>.ts`). The AES key is stored wrapped (AES-GCM) with a per-install key held in secure storage (Android Keystore / iOS Keychain, this device only), bound to the offline expiry. Playback: `video_player` (AES-128 HLS) fed by a loopback server holding the key in memory, with our watermark overlay and `FLAG_SECURE`; see §1.3 E. Downloads manager (`DownloadsController`, `DownloadsScreen`): the learning view lists each lesson's `downloads` (renditions with real sizes, lowest first) so the quality is chosen with sizes before anything is fetched; a queue runs one download at a time; "Wi-Fi only" (a phone setting in `sync_state`, off by default) makes downloads wait for Wi-Fi and pause when it goes; a network drop puts the running download back at the front and the queue waits for the network (nothing is marked failed for that); unexpected failures (e.g. storage full) fail only that download; a download deleted while running is waited for, so nothing it writes comes back; downloads carry their course and lesson titles for the offline list; jobs belong to the account that asked (an account switch stops them). Interrupted downloads resume at the next launch on Wi-Fi only and stay on Wi-Fi; a download the student paused stays paused (`download_paused:` rows in `sync_state`). Downloads run while the app is alive; an OS-level background service is still to do. Documents via background_downloader into app-private storage.
- **Device identity:** generated `install_id` stored in secure storage; sent as `X-Device-Id`.
- **Min app version:** `/config` returns `min_supported_version`; older apps show a forced-update screen.
- **API client:** generated into `mobile/lib/core/api/generated` by `tool/generate_api_client.dart`: it cuts `api/openapi.json` to the student-facing operations (admin, professor, staff, webhook and fake-gateway paths and the schemas only they use are dropped), then runs swagger_parser (plain Dio methods and plain Dart codecs, no build_runner). Unknown enum values are preserved, so older app versions survive new API values. A hand-written interceptor adds `Authorization: Bearer`, `X-Device-Id`, `Accept-Language` and `X-Request-Id`; failures become `ApiException` (the error envelope). A 401 for the current token ends the session.
- **Session:** the device token and a cached copy of the user live in secure storage and are read before the first frame, so the app opens offline. Sign-in: phone → code → (at the device limit) choose a device to sign out, retried with the same code and `replace_device_id` → name on first sign-in. Suspended accounts see only an explanation screen.
- **Flavors:** `staging` (`com.yekkola.app.staging`) and `production` (`com.yekkola.app`) on Android; the flavor picks the API host, `--dart-define=API_BASE_URL` overrides it. Android backups and device-to-device transfer are off (Keystore-bound secrets must not be restored elsewhere). iOS schemes pending.
- **CI:** `mobile.yml` (format, `flutter analyze`, client up to date, `flutter test`, staging debug APK) runs on `mobile/**` and `api/openapi.json` changes.

---

## 7. Scalability

Built so that growth is an infrastructure change, not a rewrite.

- **Stateless API instances** behind Laravel Cloud's load balancer; scale horizontally. Sessions, cache, rate limits, locks in Valkey.
- **Media bytes bypass the API.** Uploads and segments go straight to R2 through pre-signed URLs (R2 has no egress fees). The API serves only small playlists and keys; keep them cheap.
- **Transcoding** runs on dedicated `media` queue workers (FFmpeg is CPU- and memory-heavy), sized apart from the API. If Laravel Cloud workers can't run FFmpeg, use a dedicated transcoding worker or container (spike pending).
- **Queues for everything slow or external:** webhooks, payments, SMS, push, PDF stamping, certificate generation, search indexing, stats rollups. Separate queues by priority (`payments`, `default`, `notifications`, `media`, `reports`) with dedicated workers for `payments`.
- **Read-heavy public pages** cached at three levels: Next.js ISR/CDN, API response cache (Redis, tag-invalidated on publish), and Meilisearch for search/filter.
- **Database:**
  - Indexes on every foreign key and every list filter/sort column.
  - Cursor pagination for large lists; no `OFFSET` deep pages on big tables.
  - `lesson_progress`, `quiz_attempts`, `payment_events`, `webhook_events`: candidates for time/hash partitioning once they reach tens of millions of rows; old event rows archived.
  - Read replica for analytics/reporting queries when needed; dashboards read pre-aggregated `*_daily_stats`, never scan raw tables.
  - Denormalized counters (enrollment_count, rating_avg) updated by events, not `COUNT(*)` at request time.
- **Mobile sync** is batched (one request per sync, not per lesson) and incremental (`updated_since`).
- **Hot paths kept cheap:** playback, playlist and key requests are a single indexed enrollment lookup + URL signing (no external call).
- **Load testing** (k6) on catalogue, playback token, progress sync, and checkout before launch.

---

## 8. Security

- Authentication: OTP codes hashed, short TTL, attempt-limited; tokens bound to devices; sessions rotated on login; logout everywhere on phone change.
- Authorization: policies for every model action; admin routes require role + permission; finance actions require `finance.*` and are audited.
- Input: form requests on every write; mass-assignment guarded; file uploads type/size-validated and virus-scanned (queued) before being made available.
- Data: PII (phone in payout details, KYC) encrypted with Laravel encrypted casts; KYC on the private R2 bucket, streamed through the API only.
- Media: video/audio segments encrypted with AES-128, one key per asset. Keys are encrypted at rest in Postgres, never stored in R2 next to the segments, and returned only after the access checks. Encrypted HLS is weaker than DRM (anyone holding a valid key can decrypt the segments); the owner accepted this (2026-11-09). The per-viewer overlay stays as the deterrent against screen recording.
- Buckets: no public listing; the private bucket is reached only through pre-signed URLs; CORS allows browser uploads from the web origins only; separate access keys per environment.
- Webhooks: signature verification + replay protection (event ID dedupe, timestamp tolerance); payment status always re-confirmed with the gateway API.
- Web: strict CSP, HTTPS only, `SameSite=Lax` cookies, CSRF via Sanctum, Turnstile on OTP request.
- Secrets: only in Cloud environment variables; never in the repo or front-end bundles (front ends get only public config).
- Dependencies: Dependabot/Renovate; `composer audit` and `pnpm audit` in CI.

---

## 9. Observability & operations

- **Errors:** Sentry in API, both Next.js apps, Flutter — with release tags and user ID (no phone numbers in payloads).
- **Logs:** structured JSON logs with request ID propagated from clients (`X-Request-Id`).
- **Metrics/alerts:** queue depth and wait time (Horizon), failed jobs, payment success rate per rail, stuck pending payments, webhook processing lag, OTP delivery failures, transcoding failures and `media` queue wait time, API p95 latency, 5xx rate.
- **Health:** `GET /up` for uptime checks; dependency checks (DB, Redis, Meilisearch) on a separate internal endpoint.
- **Backups:** managed Postgres backups + `spatie/laravel-backup` to a separate bucket; **restore tested** on a schedule.
- **Environments:** `staging` mirrors production with fake drivers (or sandbox gateways); seeded demo data; preview deployments for front-end PRs if Cloud supports them.
- **Runbooks** (in `docs/runbooks/`, to write): stuck payments, failed payout batch, R2/transcoding outage, SMS outage, compromised admin account.

---

## 10. Testing strategy

- **API:** Pest feature tests per endpoint (auth, validation, authorization, happy path, edge cases) — these are the contract. Unit tests for the ledger (balanced transactions), state machines, price/quote calculation, and progress merge rules. Fake drivers for video, payments, SMS.
- **Contract:** CI regenerates OpenAPI + `api-client` (and the mobile client in `mobile.yml`) and fails on drift; the API workflow publishes `openapi.json` as the `openapi-spec` build artifact.
- **Web:** Vitest + Testing Library for features; Playwright end-to-end for critical journeys (sign-in, free enroll, paid checkout with fake gateway, lesson playback page, studio upload, admin approval).
- **Mobile:** unit tests for sync engine and merge logic; integration tests for download/offline playback on real devices (Android low-end device in the test matrix).
- **Non-functional:** k6 load tests in `loadtest/` (catalogue, playback, progress, checkout; test students from `php artisan loadtest:prepare` on staging); Lighthouse CI budgets on public pages; accessibility checks (axe) in Playwright.
