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
              │Postgres│ │ Valkey  │ │ Object  │ │ Meilisearch  │
              │        │ │ cache,  │ │ storage │ │ (search)     │
              │        │ │ queues, │ │ (docs,  │ │              │
              │        │ │ locks   │ │ images, │ │              │
              └────────┘ └────┬────┘ │ KYC)    │ └──────────────┘
                              │      └─────────┘
                    ┌─────────▼──────────┐
                    │ Queue workers      │──► Mux API (assets, tokens)
                    │ (Horizon)          │──► Payment gateway (collect, disburse)
                    │                    │──► SMS gateway, email, FCM
                    └────────────────────┘
   Inbound webhooks: Mux ──► /webhooks/mux   Gateway ──► /webhooks/payments/{gateway}
   Media delivery:   Mux CDN ──► players directly (never through the API)
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
| `Protection` | Playback/DRM tokens, offline licenses, document stamping, stream limits | OfflineLicense, StreamSession |
| `Finance` | Ledger, balances, payouts | LedgerAccount, LedgerTransaction, LedgerEntry, Payout, PayoutBatch |
| `Moderation` | Course review queue, reports, takedowns | Report (+ review fields on CourseVersion) |
| `Notifications` | Channels, templates, preferences | NotificationPreference |
| `Platform` | Settings, audit, stats rollups | settings, activity_log, CourseDailyStat |

Rules: domains talk to each other through **actions and events**, not by reaching into each other's tables in controllers. Cross-domain side effects (e.g. "payment succeeded → create enrollment → post ledger → notify") are driven by domain events handled by queued listeners.

### 1.3 Key flows

**A. Sign-in (phone OTP)**
1. `POST /auth/otp/request` → rate-limit check (phone + IP) → create `OtpChallenge` (hashed code, 5 min TTL) → `SmsSender` (queued).
2. `POST /auth/otp/verify` → verify hash, attempts ≤ 5 → find-or-create `User` → web: session cookie via Sanctum; mobile: register/refresh `Device`, issue a token bound to that device.

**B. Video upload (professor)**
1. Studio calls `POST /professor/media/uploads` → API calls Mux "create direct upload" (with burned-in watermark overlay + DRM playback policy) → returns upload URL + `MediaAsset` (status `waiting_upload`).
2. Browser uploads the file **directly to Mux** (resumable, chunked).
3. Mux webhook `video.asset.ready` → `/webhooks/mux` → dedupe by event ID → queue → update `MediaAsset` (`ready`, duration, playback ID).
4. Studio polls `GET /professor/media/{id}` (or listens via notifications) to show status.

**C. Paid purchase (mobile money)**
1. `POST /checkout/quote` → server computes price, discounts, currency (never trusts client totals).
2. `POST /orders` with `Idempotency-Key` → `Order(pending)` + items with **snapshotted** price and revenue split.
3. `POST /orders/{id}/payments {rail, msisdn}` → `PaymentGateway::collect()` → `Payment(pending)`; student approves the USSD prompt on their phone.
4. Gateway webhook → `/webhooks/payments/{gateway}` → dedupe → verify signature → re-query gateway for status (never trust webhook body alone) → `Payment(succeeded)`.
5. `PaymentSucceeded` event → `Order(paid)` → `Enrollment` per item → ledger transaction (gateway clearing ↔ professor earnings (held) + platform revenue) → receipt notification.
6. Client polls `GET /orders/{id}` until terminal state. A scheduled reconciliation job re-queries all payments stuck in `pending` past the timeout.

**D. Playback (streaming)**
1. Player requests `POST /lessons/{uid}/playback`.
2. API checks: active enrollment (or preview lesson), account status, concurrent-stream limit (web) or registered device (mobile).
3. Returns signed playback token + DRM license token (short TTL) + watermark text. Player streams **directly from Mux**.

**E. Offline download (mobile)**
1. App requests `POST /lessons/{uid}/offline-license {device_id}` → API checks enrollment + device is registered and not revoked → signs DRM token with `offline: true`, `licenseExpiration` = setting → records `OfflineLicense`.
2. Native Mux SDK downloads + stores the persistent license.
3. On each online sync (`GET /me/entitlements`), the app removes downloads whose enrollment or license was revoked.

**F. Progress sync (offline-first)**
1. App writes progress locally (drift) with a client timestamp.
2. When online: `PUT /me/progress` with a batch → server upserts per `(user, lesson_uid)`, keeping the furthest position / completed state (monotonic merge, never regress completion).
3. Completion events trigger certificate eligibility checks (queued).

**G. Payout run**
1. Scheduled job (per setting) builds a `PayoutBatch(draft)` from professors whose **available** balance ≥ minimum.
2. Admin (finance permission) reviews and approves → queue sends each payout via `PaymentGateway::disburse()`.
3. Webhook/poll → `Payout(paid|failed)` → ledger: professor payable → gateway clearing. Failed payouts return funds to available balance.

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
│   │   │   │                              CreateMediaUpload, HandleMediaReady, SubmitForReview, ApproveVersion, RejectVersion
│   │   │   ├── Commerce/                  Actions: QuoteCheckout, CreateOrder, InitiatePayment, HandlePaymentResult,
│   │   │   │                              ExpireStaleOrders, RequestRefund, ApproveRefund; StateMachines/OrderState, PaymentState
│   │   │   ├── Learning/                  Actions: EnrollFree, GrantEnrollment, RevokeEnrollment, SyncProgress,
│   │   │   │                              StartQuizAttempt, SubmitQuizAttempt, IssueCertificate, PostReview, PostThread
│   │   │   ├── Protection/                Actions: IssuePlaybackToken, IssueOfflineLicense, StampDocument, EnforceStreamLimit
│   │   │   ├── Finance/                   Ledger/(Ledger, Posting, AccountType), Actions: PostSale, PostRefund,
│   │   │   │                              BuildPayoutBatch, ApprovePayoutBatch, ExecutePayout, HandlePayoutResult
│   │   │   ├── Moderation/                Actions: FileReport, ResolveReport, TakedownCourse
│   │   │   ├── Notifications/             Notifications/*, Channels/(SmsChannel, FcmChannel)
│   │   │   └── Platform/                  Settings/*Settings.php, Stats/RollupCourseStats
│   │   ├── Integrations/
│   │   │   ├── Video/                     VideoProvider (interface), MuxVideoProvider, FakeVideoProvider
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
│   │   │   │                              /certificates/[serial], /teach, legal pages      ← server components, SSR/ISR
│   │   │   ├── (auth)/                    /login, /verify
│   │   │   ├── (student)/                 /my-courses, /learn/[course]/[lesson], /checkout/[orderId],
│   │   │   │                              /orders, /certificates, /wishlist, /account     ← client, auth-guarded
│   │   │   └── (studio)/studio/           /, /apply, /courses, /courses/[id]/(details|curriculum|pricing|review),
│   │   │                                  /analytics, /earnings, /payouts, /reviews, /questions, /coupons, /profile
│   │   ├── src/features/<domain>/         components/, hooks/, schemas/, utils/
│   │   ├── src/lib/                       api (fetch config), auth, i18n, analytics
│   │   └── messages/ → packages/i18n
│   └── admin/                             Next.js — back office
│       ├── src/app/[locale]/
│       │   ├── (auth)/login
│       │   └── (console)/                 /dashboard, /users, /professors, /applications,
│       │                                  /moderation/(courses|reports|reviews), /catalog/(courses|categories|featured),
│       │                                  /commerce/(orders|payments|refunds|coupons|bundles),
│       │                                  /finance/(ledger|payouts|batches), /settings, /audit-log, /staff
│       └── src/features/<domain>/
├── packages/
│   ├── ui/                                shadcn/ui primitives + blocks (DataTable, KpiCard, ChartCard, PageHeader,
│   │                                      EmptyState, FileUpload, ConfirmDialog, StatusBadge, MoneyText), theme tokens
│   ├── api-client/                        Orval output: typed fetchers, TanStack Query hooks, Zod schemas
│   ├── i18n/                              fr.json, en.json (namespaced), shared formatters (money, dates, phone)
│   └── config/                            tsconfig, eslint, tailwind preset, prettier
├── mobile/                                Flutter (phase 3)
│   ├── lib/
│   │   ├── core/                          api client, auth, db (drift), sync engine, i18n, theme
│   │   ├── features/<feature>/            data/, domain/, presentation/
│   │   └── main.dart
│   └── packages/mux_player_plugin/        In-house plugin: Android (Kotlin) + iOS (Swift) wrappers
├── docs/                                  architecture.md, prd/
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
| `users` | id, phone_e164 (unique), phone_verified_at, email (unique, null), email_verified_at, name, locale (`fr`/`en`), province_id (null), city (null), status (`active`/`suspended`/`banned`), last_seen_at, deleted_at | Phone is the identity. |
| `otp_challenges` | id, phone_e164, purpose (`login`/`change_phone`), code_hash, attempts, expires_at, consumed_at, ip, user_agent | Prune after 24 h. |
| `devices` | id, user_id, platform (`android`/`ios`), install_id (unique per user), name, app_version, push_token, registered_at, last_active_at, revoked_at | Counts toward the registered device limit when `revoked_at` is null. |
| `personal_access_tokens` | Sanctum + `device_id` | Mobile tokens are bound to a device. |
| `stream_sessions` | id, user_id, client (`web`/`android`/`ios`), lesson_uid, started_at, last_heartbeat_at, ended_at | Enforces concurrent web streams. Short-lived; can live in Redis instead. |
| `provinces` | id, code, name | Seeded with the 26 DRC provinces. |
| `roles`, `permissions`, … | spatie/laravel-permission | Roles: student, professor, moderator, admin; permission sets e.g. `finance.*`. |

### 3.2 Professors

| Table | Columns (key) | Notes |
|---|---|---|
| `professor_applications` | id, user_id, status (`submitted`/`under_review`/`approved`/`rejected`/`needs_info`), expertise, subjects (jsonb), credentials (jsonb), sample_media_url, motivation, reviewer_id, decision_note, decided_at | One open application per user. |
| `professor_profiles` | id, user_id (unique), slug (unique), display_name, headline, bio, avatar (media), province_id, status (`active`/`suspended`), verified_at, revenue_share_bps (null = default), kyc_status (`pending`/`verified`/`rejected`), payout_rail, payout_msisdn (encrypted), payout_name, rating_avg, rating_count, student_count | Created on application approval. |
| `kyc_documents` | id, professor_profile_id, type (`national_id`/`voter_card`/`passport`/`other`), media (private disk), status, reviewed_by, reviewed_at | Private bucket only. |

### 3.3 Catalog & authoring

| Table | Columns (key) | Notes |
|---|---|---|
| `categories` | id, parent_id (null), slug, name (jsonb), description (jsonb), icon, position, is_visible | Two levels max. |
| `courses` | id, professor_id, slug (unique), status (`draft`/`in_review`/`published`/`unpublished`/`archived`), live_version_id (null), draft_version_id (null), category_id, language (`fr`/`en`), level (`beginner`/`intermediate`/`advanced`/`all`), audience (jsonb tags e.g. `exetat`, `university`), is_free, price_minor, currency, revenue_share_bps (null), is_featured, published_at, rating_avg, rating_count, enrollment_count, total_duration_seconds, deleted_at | Denormalized counters updated by events. |
| `course_versions` | id, course_id, number, state (`draft`/`in_review`/`approved`/`rejected`/`superseded`), title, subtitle, description, outcomes (jsonb), requirements (jsonb), cover_image (media), promo_lesson_uid (null), submitted_at, reviewed_by, reviewed_at, review_notes | Approved versions are immutable. |
| `sections` | id, course_version_id, title, position | |
| `lessons` | id, course_version_id, section_id, uid (stable across versions), type (`video`/`audio`/`document`/`quiz`), title, description, position, is_preview, duration_seconds, media_asset_id (null), document_media_id (null), quiz_id (null) | Unique `(course_version_id, uid)`. New version = copy rows, keep `uid`. |
| `media_assets` | id, professor_id, kind (`video`/`audio`), provider (`mux`), provider_upload_id, provider_asset_id, playback_id, status (`waiting_upload`/`processing`/`ready`/`errored`), duration_seconds, max_resolution, size_bytes_estimates (jsonb per tier), error (jsonb) | Reusable across versions (copying a version doesn't re-upload). |
| `quizzes` | id, course_version_id, title, pass_mark_pct, time_limit_seconds (null), max_attempts (null), shuffle_questions | |
| `quiz_questions` | id, quiz_id, type (`single`/`multiple`/`true_false`), prompt, explanation, points, position, media (null) | |
| `quiz_options` | id, quiz_question_id, text, is_correct, position | `is_correct` never sent to students before submission. |

### 3.4 Commerce

| Table | Columns (key) | Notes |
|---|---|---|
| `orders` | id, number (human-readable, unique), buyer_id, beneficiary_id (student who gets access), payer_msisdn, status (`pending`/`awaiting_payment`/`paid`/`failed`/`expired`/`cancelled`/`refunded`/`partially_refunded`), currency, subtotal_minor, discount_minor, total_minor, coupon_id (null), idempotency_key (unique per buyer), expires_at, paid_at | Single currency per order. |
| `order_items` | id, order_id, course_id, bundle_id (null), professor_id, list_price_minor, discount_minor, price_minor, discount_funded_by (`platform`/`professor`/`shared`), revenue_share_bps (snapshot), currency | Snapshot = what the ledger uses. |
| `payments` | id, order_id, gateway, rail (`orange`/`airtel`/`mpesa`), msisdn (encrypted), amount_minor, currency, status (`initiated`/`pending`/`succeeded`/`failed`/`expired`/`reversed`), gateway_reference (unique), failure_code, failure_message, last_checked_at, attempts | Multiple attempts per order allowed. |
| `payment_events` | id, payment_id (null), gateway, event_id (unique per gateway), type, payload (jsonb), signature_valid, received_at, processed_at, error | Inbox for webhooks + polls. |
| `refunds` | id, order_id, order_item_id, amount_minor, currency, reason, status (`requested`/`approved`/`rejected`/`processing`/`completed`/`failed`), requested_by, decided_by, decided_at, gateway_reference | |
| `coupons` | id, code (unique, case-insensitive), scope (`platform`/`professor`/`course`), professor_id (null), course_id (null), discount_type (`percent`/`fixed`), value, currency (null for percent), funded_by, max_redemptions, per_user_limit, starts_at, ends_at, is_active, created_by | |
| `coupon_redemptions` | id, coupon_id, user_id, order_id | Counted on payment success, not order creation. |
| `bundles` | id, professor_id (null = platform bundle), slug, title (jsonb or text), price_minor, currency, status | |
| `bundle_courses` | bundle_id, course_id, position | |

### 3.5 Learning

| Table | Columns (key) | Notes |
|---|---|---|
| `enrollments` | id, user_id, course_id, source (`purchase`/`free`/`coupon`/`bundle`/`admin`), order_item_id (null), status (`active`/`revoked`), revoked_reason, revoked_at, enrolled_at, completed_at, progress_pct, last_lesson_uid, last_activity_at | Unique `(user_id, course_id)`. The only access check. |
| `lesson_progress` | id, user_id, course_id, lesson_uid, status (`not_started`/`in_progress`/`completed`), position_seconds, completed_at, client_updated_at, device_id | Unique `(user_id, lesson_uid)`. Largest table — see §7. |
| `quiz_attempts` | id, user_id, quiz_id, lesson_uid, started_at, submitted_at, score_pct, passed, answers (jsonb), device_id | Offline attempts allowed (submitted on sync). |
| `certificates` | id, serial (unique, public), user_id, course_id, course_version_id, student_name_snapshot, course_title_snapshot, professor_name_snapshot, issued_at, pdf (media), revoked_at | |
| `reviews` | id, user_id, course_id, rating (1–5), body, status (`visible`/`hidden`/`flagged`), professor_reply, replied_at | Unique `(user_id, course_id)`; requires enrollment. |
| `lesson_threads` | id, course_id, lesson_uid, author_id, title, body, status (`open`/`answered`/`closed`/`hidden`), reply_count, last_reply_at | |
| `thread_replies` | id, lesson_thread_id, author_id, body, is_professor, status | |
| `wishlist_items` | user_id, course_id, created_at | PK `(user_id, course_id)`. |

### 3.6 Protection

| Table | Columns (key) | Notes |
|---|---|---|
| `offline_licenses` | id, user_id, device_id, lesson_uid, media_asset_id, issued_at, expires_at, revoked_at | Record of persistent licenses issued; drives entitlement sync and audits. |
| `document_stamps` | id, user_id, document_media_id, stamped_media (private), created_at | Cache of per-student stamped PDFs; regenerated if source changes. |

### 3.7 Finance (double-entry ledger)

| Table | Columns (key) | Notes |
|---|---|---|
| `ledger_accounts` | id, type, owner_type, owner_id (null), currency, unique `(type, owner_type, owner_id, currency)` | Types: `gateway_clearing`, `platform_revenue`, `professor_held`, `professor_available`, `professor_paid_out`, `refunds_payable`, `discounts_platform`. |
| `ledger_transactions` | id, type (`sale`/`release`/`refund`/`payout`/`payout_failed`/`adjustment`), reference_type, reference_id, memo, created_by (null), created_at | Immutable. |
| `ledger_entries` | id, ledger_transaction_id, ledger_account_id, direction (`debit`/`credit`), amount_minor, currency, created_at | Sum of debits = sum of credits per transaction (enforced in code + DB check via trigger or test). |
| `payout_batches` | id, status (`draft`/`approved`/`processing`/`completed`/`partially_failed`), currency, period_end, created_by, approved_by, approved_at | |
| `payouts` | id, payout_batch_id, professor_id, amount_minor, currency, rail, msisdn (encrypted), status (`pending`/`processing`/`paid`/`failed`), gateway_reference, failure_reason, paid_at | |

Balances (`held`, `available`, `paid_out`) are computed from entries; a cached balance per account may be kept in Redis/materialized view but is never the source of truth.

### 3.8 Moderation, notifications, platform

| Table | Columns (key) | Notes |
|---|---|---|
| `reports` | id, reporter_id, reportable_type (`course`/`review`/`thread`/`reply`/`professor`), reportable_id, reason, details, status (`open`/`actioned`/`dismissed`), handled_by, resolution_note, handled_at | |
| `notifications` | Laravel database notifications | In-app feed. |
| `notification_preferences` | user_id, type, channel (`push`/`sms`/`email`/`in_app`), enabled | Defaults in code; rows only for overrides. |
| `settings` | spatie/laravel-settings | |
| `activity_log` | spatie/laravel-activitylog | All admin actions + settings changes. |
| `webhook_events` | id, provider (`mux`/…), event_id (unique per provider), type, payload, received_at, processed_at, error | Mux inbox (payments use `payment_events`). |
| `course_daily_stats` | course_id, date, views, enrollments, paid_enrollments, revenue_minor, currency, completions, avg_rating | Rolled up nightly + incrementally; feeds dashboards. |
| `platform_daily_stats` | date, signups, active_students, orders, gmv_minor (per currency), payouts_minor, … | Admin dashboard. |

---

## 4. API

### 4.1 Conventions

- Base: `https://api.yekkola.<tld>/api/v1`. JSON only. Versioned by path; breaking changes → `/v2`.
- Auth: web/admin use Sanctum SPA cookies (`GET /sanctum/csrf-cookie` first; `X-XSRF-TOKEN`); mobile uses `Authorization: Bearer <token>` + `X-Device-Id`.
- Locale: `Accept-Language: fr|en` → messages, validation errors, translatable fields.
- IDs: ULIDs; public pages use slugs.
- Lists: `?page[size]=&page[number]=` (offset) for admin tables; `?cursor=` for infinite feeds and large tables. Filters `?filter[status]=…`, sorting `?sort=-created_at`, includes `?include=professor`.
- Envelope: `{ "data": …, "meta": { pagination… }, "links": {…} }`.
- Errors: RFC 9457-style `{ "type", "title", "status", "detail", "code", "errors": { field: [msg] } }` with a stable machine `code` (e.g. `otp.expired`, `enrollment.required`, `device.limit_reached`).
- Idempotency: `Idempotency-Key` header required on `POST /orders`, `POST /orders/{id}/payments`, payout approvals; replays return the original response.
- Rate limits (per user/IP/phone): OTP request 3/10 min per phone, 10/h per IP; OTP verify 5 attempts per challenge; payments 10/h per user; default 120/min per user.
- Webhooks: signature-verified, deduplicated by event ID, acknowledged fast (`2xx`), processed on the queue.

### 4.2 Endpoint catalogue

**Auth & account** (`/auth`, `/me`)

| Method | Path | Purpose |
|---|---|---|
| POST | /auth/otp/request | Send OTP to phone |
| POST | /auth/otp/verify | Verify OTP → session (web) or token (mobile, with device payload) |
| POST | /auth/logout | End session / revoke current token |
| GET / PATCH | /me | Profile (name, email, locale, province) |
| POST | /me/phone/change | Change phone (OTP to new number) |
| GET | /me/devices | List registered devices |
| DELETE | /me/devices/{id} | Remove device (revokes token + offline licenses) |
| POST | /me/export | Request data export |
| DELETE | /me | Request account deletion |

**Public**

| Method | Path | Purpose |
|---|---|---|
| GET | /config | Public settings: currencies, locales, feature flags, min app version |
| GET | /categories | Category tree |
| GET | /courses | Catalogue + search (`q`, category, price `free`/`paid`, language, level, audience, sort) |
| GET | /courses/{slug} | Course detail (live version, curriculum outline, preview lessons) |
| GET | /courses/{slug}/reviews | Reviews (paginated) |
| GET | /professors/{slug} | Professor profile + courses |
| GET | /certificates/{serial} | Certificate verification |
| GET | /featured | Home page rails (featured, popular, new, free) |

**Student**

| Method | Path | Purpose |
|---|---|---|
| GET | /me/enrollments | My courses with progress |
| POST | /courses/{id}/enroll | Enroll in a free course |
| GET | /me/courses/{id} | Learning view: curriculum + my progress |
| POST | /lessons/{uid}/playback | Playback + DRM tokens + watermark text (stream) |
| POST | /lessons/{uid}/playback/heartbeat | Keep stream session alive (concurrency) |
| POST | /lessons/{uid}/offline-license | Persistent license for a registered device |
| GET | /lessons/{uid}/document | Short-lived signed URL to the stamped PDF |
| GET | /me/entitlements | Mobile sync: active enrollments, revoked items, license states |
| PUT | /me/progress | Batch progress sync |
| POST | /quizzes/{id}/attempts | Start attempt (questions without answers) |
| PUT | /quiz-attempts/{id} | Submit answers → score, explanations |
| GET | /me/certificates | My certificates |
| POST / PATCH / DELETE | /courses/{id}/review | My review |
| GET / POST | /lessons/{uid}/threads | Lesson Q&A |
| POST | /threads/{id}/replies | Reply |
| POST | /reports | Report content |
| GET / POST / DELETE | /me/wishlist[/{courseId}] | Wishlist |
| GET | /me/notifications | In-app feed; `POST /me/notifications/read` |
| GET / PUT | /me/notification-preferences | Preferences |

**Commerce**

| Method | Path | Purpose |
|---|---|---|
| POST | /checkout/quote | Price quote (courses or bundle, coupon, currency) |
| POST | /orders | Create order (`Idempotency-Key`) |
| POST | /orders/{id}/payments | Start payment `{rail, msisdn}` |
| GET | /orders/{id} | Order + latest payment status (polling) |
| POST | /orders/{id}/cancel | Cancel unpaid order |
| GET | /me/orders | Order history |
| GET | /me/orders/{id}/receipt | Receipt (JSON + PDF link) |
| POST | /orders/{id}/refund-requests | Request refund |

**Professor** (`/professor`, role: professor except application endpoints)

| Method | Path | Purpose |
|---|---|---|
| POST / GET | /professor/application | Apply / application status (any user) |
| GET / PATCH | /professor/profile | Public profile |
| POST | /professor/kyc-documents | Upload KYC (pre-signed upload) |
| PATCH | /professor/payout-method | Rail + number (OTP-confirmed) |
| GET / POST | /professor/courses | List / create |
| GET / PATCH / DELETE | /professor/courses/{id} | Course settings (category, language, price, free) |
| POST | /professor/courses/{id}/draft | Start a draft from the live version |
| GET / PATCH | /professor/courses/{id}/draft | Draft metadata |
| POST / PATCH / DELETE | /professor/courses/{id}/draft/sections[/{sid}] | Sections |
| POST / PATCH / DELETE | /professor/courses/{id}/draft/lessons[/{lid}] | Lessons |
| PUT | /professor/courses/{id}/draft/order | Reorder sections + lessons |
| POST | /professor/courses/{id}/draft/submit | Submit for review |
| POST | /professor/courses/{id}/unpublish | Unpublish |
| POST | /professor/media/uploads | Mux direct-upload URL (`kind` video/audio) |
| GET | /professor/media/{id} | Media status |
| POST | /professor/documents/uploads | Pre-signed upload for a document |
| CRUD | /professor/quizzes/{id}/questions | Quiz builder |
| GET | /professor/analytics/overview | KPIs + time series |
| GET | /professor/analytics/courses/{id} | Per-course stats, funnel, quiz performance |
| GET | /professor/earnings | Balances (held/available/paid) |
| GET | /professor/ledger | Earnings statement |
| GET | /professor/payouts | Payout history |
| GET | /professor/reviews | Reviews; `POST /professor/reviews/{id}/reply` |
| GET | /professor/threads | Q&A inbox |
| CRUD | /professor/coupons | Professor-funded coupons |

**Admin** (`/admin`, role: admin/moderator; finance endpoints need `finance.*`)

| Method | Path | Purpose |
|---|---|---|
| GET | /admin/dashboard | KPIs + charts |
| GET / PATCH | /admin/users[/{id}] | Search, view, suspend/ban/reactivate |
| POST | /admin/users/{id}/enrollments | Grant enrollment |
| GET | /admin/professor-applications | Queue |
| POST | /admin/professor-applications/{id}/(approve\|reject\|request-info) | Decide |
| GET / PATCH | /admin/professors[/{id}] | Suspend, revenue-share override, verify KYC |
| GET | /admin/course-versions?state=in_review | Content review queue |
| POST | /admin/course-versions/{id}/(approve\|reject) | Decide with notes |
| GET / PATCH | /admin/courses[/{id}] | Unpublish, feature, takedown |
| CRUD | /admin/categories (+ `PUT /admin/categories/order`) | Categories |
| GET | /admin/reports; POST /admin/reports/{id}/resolve | Moderation |
| PATCH | /admin/reviews/{id}, /admin/threads/{id} | Hide/restore |
| GET | /admin/orders[/{id}], /admin/payments[/{id}] | Commerce views |
| POST | /admin/payments/{id}/recheck | Force status re-query |
| GET | /admin/refunds; POST /admin/refunds/{id}/(approve\|reject) | Refunds |
| CRUD | /admin/coupons, /admin/bundles | Promotions |
| GET | /admin/ledger/accounts, /admin/ledger/transactions | Finance views |
| POST | /admin/ledger/adjustments | Manual adjustment (finance, audited) |
| GET / POST | /admin/payout-batches | List / build |
| POST | /admin/payout-batches/{id}/(approve\|execute) | Run payouts |
| GET / PUT | /admin/settings | Platform settings |
| GET | /admin/audit-log | Activity log |
| CRUD | /admin/staff | Staff accounts + roles |

**Webhooks** (no auth; signature-verified)

| Method | Path |
|---|---|
| POST | /webhooks/mux |
| POST | /webhooks/payments/{gateway} |
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
- **Student area:** client components behind an auth guard (`/me` query). Learning page: Mux Player + `WatermarkOverlay` + curriculum sidebar + Q&A tab; progress posted on intervals and on pause/end.
- **Checkout:** order → rail picker → number input → "approve on your phone" waiting screen polling `GET /orders/{id}` with backoff → success/failure states with retry.
- **Professor studio:** dashboard (KPI cards + charts), course list (DataTable), course editor (tabs: details, curriculum drag-and-drop, pricing, review status), media uploader (Mux Uploader with progress + resumable), quiz builder, earnings/payouts tables, reviews & Q&A inbox.

### 5.3 apps/admin

- Shell: sidebar navigation by domain, command palette (search users/courses/orders by ID or phone), breadcrumb, locale switch.
- Every list page = `DataTable` with server pagination/sort/filter, URL-synced, column visibility, row selection, bulk actions, CSV export (server-generated for large sets).
- Detail pages: header (status badge + primary actions) + tabs (overview, related records, activity log).
- Review screens: side-by-side course version diff (what changed vs live), lesson preview with player, approve/reject with required notes.
- Dashboards: KPI cards (GMV, net revenue, new students, active students, conversion), time-series charts, queues needing attention (pending applications, versions in review, open reports, stuck payments, failed payouts).
- Destructive or financial actions always go through `ConfirmDialog` with a typed reason; reasons are stored in the audit log.

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
- **Entitlement sync:** on app start and every sync, `GET /me/entitlements`; revoked items are deleted from disk and the drift DB.
- **Downloads:** `mux_player_plugin` exposes `download(lessonUid, tokens, maxResolution)`, `progress` stream, `delete`, `listDownloads`, `play(lessonUid, watermarkText)`. Documents via background_downloader into app-private storage.
- **Device identity:** generated `install_id` stored in secure storage; sent as `X-Device-Id`.
- **Min app version:** `/config` returns `min_supported_version`; older apps show a forced-update screen.

---

## 7. Scalability

Built so that growth is an infrastructure change, not a rewrite.

- **Stateless API instances** behind Laravel Cloud's load balancer; scale horizontally. Sessions, cache, rate limits, locks in Valkey.
- **Media bypasses the API entirely** (Mux CDN for video/audio; pre-signed object-storage URLs for documents) — the biggest load never hits our servers.
- **Queues for everything slow or external:** webhooks, payments, SMS, push, PDF stamping, certificate generation, search indexing, stats rollups. Separate queues by priority (`payments`, `default`, `notifications`, `media`, `reports`) with dedicated workers for `payments`.
- **Read-heavy public pages** cached at three levels: Next.js ISR/CDN, API response cache (Redis, tag-invalidated on publish), and Meilisearch for search/filter.
- **Database:**
  - Indexes on every foreign key and every list filter/sort column.
  - Cursor pagination for large lists; no `OFFSET` deep pages on big tables.
  - `lesson_progress`, `quiz_attempts`, `payment_events`, `webhook_events`: candidates for time/hash partitioning once they reach tens of millions of rows; old event rows archived.
  - Read replica for analytics/reporting queries when needed; dashboards read pre-aggregated `*_daily_stats`, never scan raw tables.
  - Denormalized counters (enrollment_count, rating_avg) updated by events, not `COUNT(*)` at request time.
- **Mobile sync** is batched (one request per sync, not per lesson) and incremental (`updated_since`).
- **Hot paths kept cheap:** playback token issuance is a single indexed enrollment lookup + JWT signing (no external call).
- **Load testing** (k6) on catalogue, playback token, progress sync, and checkout before launch.

---

## 8. Security

- Authentication: OTP codes hashed, short TTL, attempt-limited; tokens bound to devices; sessions rotated on login; logout everywhere on phone change.
- Authorization: policies for every model action; admin routes require role + permission; finance actions require `finance.*` and are audited.
- Input: form requests on every write; mass-assignment guarded; file uploads type/size-validated and virus-scanned (queued) before being made available.
- Data: PII (phone in payout details, KYC) encrypted with Laravel encrypted casts; KYC on a private disk; signed short-lived URLs only.
- Webhooks: signature verification + replay protection (event ID dedupe, timestamp tolerance); payment status always re-confirmed with the gateway API.
- Web: strict CSP, HTTPS only, `SameSite=Lax` cookies, CSRF via Sanctum, Turnstile on OTP request.
- Secrets: only in Cloud environment variables; never in the repo or front-end bundles (front ends get only public config).
- Dependencies: Dependabot/Renovate; `composer audit` and `pnpm audit` in CI.

---

## 9. Observability & operations

- **Errors:** Sentry in API, both Next.js apps, Flutter — with release tags and user ID (no phone numbers in payloads).
- **Logs:** structured JSON logs with request ID propagated from clients (`X-Request-Id`).
- **Metrics/alerts:** queue depth and wait time (Horizon), failed jobs, payment success rate per rail, stuck pending payments, webhook processing lag, OTP delivery failures, Mux asset errors, API p95 latency, 5xx rate.
- **Health:** `GET /up` for uptime checks; dependency checks (DB, Redis, Meilisearch) on a separate internal endpoint.
- **Backups:** managed Postgres backups + `spatie/laravel-backup` to a separate bucket; **restore tested** on a schedule.
- **Environments:** `staging` mirrors production with fake drivers (or sandbox gateways); seeded demo data; preview deployments for front-end PRs if Cloud supports them.
- **Runbooks** (in `docs/runbooks/`, to write): stuck payments, failed payout batch, Mux outage, SMS outage, compromised admin account.

---

## 10. Testing strategy

- **API:** Pest feature tests per endpoint (auth, validation, authorization, happy path, edge cases) — these are the contract. Unit tests for the ledger (balanced transactions), state machines, price/quote calculation, and progress merge rules. Fake drivers for Mux, payments, SMS.
- **Contract:** CI regenerates OpenAPI + `api-client` and fails on drift.
- **Web:** Vitest + Testing Library for features; Playwright end-to-end for critical journeys (sign-in, free enroll, paid checkout with fake gateway, lesson playback page, studio upload, admin approval).
- **Mobile:** unit tests for sync engine and merge logic; integration tests for download/offline playback on real devices (Android low-end device in the test matrix).
- **Non-functional:** k6 load tests; Lighthouse CI budgets on public pages; accessibility checks (axe) in Playwright.
