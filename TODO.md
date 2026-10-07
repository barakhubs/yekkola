# Yekkola — Project TODO

> Single list of everything from start to launch. **Update it in the same PR as the work** (see *How to update*).
> Last updated: 2026-10-07

## Now

- **Current branch:** `docs/project-todo` — this TODO list
- **Next up:** Phase 0 decisions + Mux spike, then Phase 1.1 (API scaffold)

## Legend

`[ ]` to do · `[~]` in progress · `[x]` done · `[-]` dropped (with reason) · **(blocked: …)** waiting on something
References: `PRD-NN FR-NN`, `arch §N` = `docs/architecture.md` section.

---

## Phase 0 — Foundations & decisions

### 0.1 Docs & tooling
- [x] Project context (`project-context.md`)
- [x] Architecture doc (`docs/architecture.md`)
- [x] PRDs 01–10 (`docs/prd/`)
- [x] Folder structure + CLAUDE.md files
- [x] Claude Code setup: settings, rules, skills, subagents, commands (PR #1)
- [x] Git workflow + SOLID guidelines (PR #1)
- [~] Project TODO list (this file)
- [ ] `.gitattributes` (`* text=auto eol=lf`) to stop CRLF/LF churn
- [ ] Runbooks skeleton in `docs/runbooks/` (stuck payments, failed payouts, Mux outage, SMS outage, compromised admin)

### 0.2 Brand
- [x] Brand colours: primary `#2c3892`, secondary `#fdb73b`
- [ ] Logo (full, icon/mark, monochrome, app icon, favicon)
- [ ] Typography (heading + body fonts)
- [ ] Tagline (FR/EN) — candidates: *Les meilleurs profs du Congo, partout au Congo* / *Le Congo apprend du Congo*
- [ ] Full colour scales (50–950) + dark-mode tints, contrast-checked
- [ ] Domain name(s) registered (`api.`, `www.`, `admin.` on one parent domain)

### 0.3 Accounts & services
- [ ] GitHub repo settings: branch protection on `main`, required checks, squash/merge policy
- [ ] Laravel Cloud organisation + `staging` and `production` environments
- [ ] Mux account (DRM enabled, signing keys, webhook secret)
- [ ] Meilisearch Cloud project
- [ ] Sentry projects (api, web, admin, mobile)
- [ ] PostHog (or equivalent) project
- [ ] Transactional email provider (Postmark / Resend / SES) — **Open** (PRD-10)
- [ ] Firebase project (FCM) for push
- [ ] Google Play + Apple developer accounts (needed by phase 3)

### 0.4 Technical spikes (de-risk early)
- [ ] Mux spike: direct upload → DRM playback on web → per-viewer overlay (PRD-07)
- [ ] Mux spike: offline DRM download + playback on Android and iOS native SDKs (PRD-07 FR-14)
- [ ] Verify Mux DRM + offline for **audio-only** assets (PRD-07 §10)
- [ ] Verify Mux download resolution tiers per platform
- [ ] Mux pricing estimate at projected catalogue size
- [ ] Laravel Cloud: deploy a test Next.js app from a monorepo subfolder (pnpm workspace build from root)
- [ ] Latency benchmark from several DRC cities to `eu-west-2` vs `eu-central-1` → choose region

### 0.5 Business & legal decisions (record with `/decide`)
- [ ] Default revenue split
- [ ] Payout schedule + minimum payout
- [ ] Active currencies at launch (USD / CDF / both)
- [ ] Launch categories (academic vs professional/vocational)
- [ ] Tax treatment (VAT on sales, withholding on payouts) — accountant
- [ ] Legal entity; terms of service; privacy policy; professor agreement — counsel
- [ ] Minimum age + minors' data policy
- [ ] Disbursement fee responsibility (PRD-08 BR-03)
- [ ] Bundle containing an owned course: discount or block (PRD-05 §10)
- [ ] Review SLA targets (PRD-09 §9)
- [ ] WhatsApp as a notification channel? (PRD-10 §8)

---

## Phase 1 — Backend (API)

### 1.1 Scaffold & CI
- [ ] Laravel API-only app in `api/` (pin version), PHP 8.4, PostgreSQL, Valkey
- [ ] Packages: Sanctum, Horizon, Scout + Meilisearch, query-builder, permission, medialibrary, settings, translatable, activitylog, backup, Scramble, mux-php
- [ ] Pest, PHPStan (Larastan), Pint configured
- [ ] Base structure: `app/Domain/*`, `app/Integrations/*`, `routes/api_v1.php`, error envelope, `SetLocaleFromHeader`, `IdempotencyKey` middleware
- [ ] `Money` and `PhoneNumber` value objects + tests
- [ ] `lang/fr` + `lang/en` scaffolding
- [ ] GitHub Actions: `api.yml` (tests, PHPStan, Pint) with path filters
- [ ] Laravel Cloud `yekkola-api` app + staging deploy + queue workers
- [ ] Update CLAUDE.md "Commands" sections with real commands

### 1.2 Platform core
- [ ] Settings classes + seeded defaults (project-context *Platform settings*)
- [ ] Roles & permissions seed (student, professor, moderator, admin, `finance.*`)
- [ ] Provinces seed (26)
- [ ] Audit log wiring for admin actions
- [ ] Integration interfaces + fakes: `VideoProvider`/Fake, `PaymentGateway`/FakeGateway, `SmsSender`/LogSmsSender (+ shared contract tests)
- [ ] Production boot guard against fake/log drivers

### 1.3 Auth & identity — PRD-01
- [ ] Users, OTP challenges, devices tables (arch §3.1)
- [ ] OTP request/verify with rate limits (FR-01–03, FR-10)
- [ ] Web session (Sanctum SPA) + mobile device-bound tokens (FR-05, FR-06)
- [ ] Profile, locale, province (FR-04)
- [ ] Device list/remove + limit (FR-07, FR-08)
- [ ] Change phone (FR-09)
- [ ] Suspend/ban behaviour (FR-15)
- [ ] Account deletion + data export (FR-11, FR-12)

### 1.4 Professors — PRD-02
- [ ] Applications: submit, draft, status (FR-01–03)
- [ ] Profiles + public slug (FR-05, FR-07)
- [ ] KYC documents on private disk (FR-08)
- [ ] Payout method with OTP + hold (FR-09)
- [ ] Terms acceptance versioning (FR-10)
- [ ] Suspension effects (FR-11)

### 1.5 Authoring — PRD-03
- [ ] Courses, versions, sections, lessons, media assets, quizzes schema (arch §3.3)
- [ ] Course CRUD + details validation (FR-01, FR-02)
- [ ] Curriculum CRUD + reorder (FR-03)
- [ ] Mux direct upload + webhook handling + burned-in watermark (FR-04, FR-05)
- [ ] Document upload + virus scan (FR-06)
- [ ] Quiz builder API (FR-07)
- [ ] Previews, pricing, free toggle, free-course limits (FR-08, FR-09, FR-15, FR-18)
- [ ] Submit / withdraw / approve / reject / new draft from live (FR-10–13)
- [ ] Unpublish / archive / delete rules (FR-16, FR-17)

### 1.6 Catalogue & search — PRD-04
- [ ] Categories API (tree, fr/en)
- [ ] Course + professor public endpoints, featured rails (FR-01, FR-05, FR-07)
- [ ] Meilisearch indexing + filters + French typo tolerance (FR-03, FR-11)
- [ ] Certificate verification endpoint (FR-09)
- [ ] Wishlist (FR-08)

### 1.7 Checkout & payments — PRD-05 (fake gateway)
- [ ] Orders, items, payments, events, refunds, coupons, bundles schema (arch §3.4)
- [ ] Free enroll (FR-01)
- [ ] Quote + order creation with idempotency + snapshots (FR-02–04)
- [ ] Payment start, webhook inbox, re-query, state machine (FR-05–09)
- [ ] Reconciliation + expiry job (FR-10)
- [ ] Coupons (FR-11, FR-12) and bundles (FR-13)
- [ ] Parent payer (FR-14)
- [ ] Receipts PDF (FR-15)
- [ ] Refund request + approval flow (FR-16)

### 1.8 Learning — PRD-06
- [ ] Enrollments, progress, quiz attempts, certificates, reviews, threads schema (arch §3.5)
- [ ] My courses + learning view endpoints (FR-01, FR-02)
- [ ] Progress batch sync with monotonic merge (FR-05)
- [ ] Quiz attempts: start/submit, server timing, attempts limit (FR-07)
- [ ] Certificates: rule, PDF, serial, revocation (FR-10, FR-11)
- [ ] Reviews + professor reply (FR-12)
- [ ] Lesson Q&A threads (FR-13)
- [ ] Reports (FR-14)

### 1.9 Content protection — PRD-07
- [ ] Playback + DRM token endpoint with checks (FR-01, FR-02)
- [ ] Stream sessions + concurrent web limit (FR-05)
- [ ] Offline license endpoint + `offline_licenses` (FR-07)
- [ ] Entitlement sync endpoint (FR-10)
- [ ] Per-student PDF stamping + signed URLs (FR-12)
- [ ] Takedown/revocation effects (FR-15)

### 1.10 Finance — PRD-08
- [ ] Ledger accounts/transactions/entries + posting service (FR-01)
- [ ] Sale, release, refund postings (FR-02–05)
- [ ] Payout batches: build, approve, execute (fake disbursement), results (FR-06–08, FR-13)
- [ ] Earnings + statement endpoints (FR-09)
- [ ] Manual adjustments (FR-11)
- [ ] Ledger integrity job (FR-12)

### 1.11 Admin API — PRD-09
- [ ] Dashboard stats + rollup jobs (`course_daily_stats`, `platform_daily_stats`)
- [ ] Users, applications, professors, KYC endpoints
- [ ] Content review queue + version diff data
- [ ] Courses, categories, featured, takedown
- [ ] Moderation (reports, reviews, threads)
- [ ] Orders, payments (recheck), refunds
- [ ] Coupons, bundles
- [ ] Ledger explorer, adjustments, payout batches
- [ ] Settings + audit log + staff endpoints

### 1.12 Notifications — PRD-10
- [ ] Notification classes per catalogue event, fr/en templates (FR-01, FR-02)
- [ ] In-app feed + preferences (FR-03, FR-05)
- [ ] FCM channel + device tokens (FR-04)
- [ ] Email channel + professor digest (FR-06)
- [ ] Admin alert routing (FR-09)

### 1.13 Backend hardening
- [ ] OpenAPI spec complete and published as CI artifact
- [ ] Rate limits on all sensitive endpoints
- [ ] k6 load tests: catalogue, playback token, progress sync, checkout
- [ ] Backups configured + restore test on staging

---

## Phase 2 — Web

### 2.1 Front-end foundations
- [ ] pnpm workspace + Turborepo at repo root
- [ ] `packages/config` (tsconfig, ESLint, Tailwind preset, Prettier)
- [ ] `packages/ui`: shadcn/ui setup, brand tokens (light/dark), blocks (DataTable, KpiCard, ChartCard, PageHeader, EmptyState, FileUpload, ConfirmDialog, StatusBadge, MoneyText)
- [ ] `packages/i18n`: fr/en catalogues + money/date/phone formatters
- [ ] `packages/api-client`: Orval config + generation script
- [ ] CI: `web.yml` (lint, typecheck, tests) + `contract.yml` (client drift check)
- [ ] Laravel Cloud apps `yekkola-web` and `yekkola-admin` + staging deploys

### 2.2 Back office (apps/admin) — PRD-09 (before the student web app)
- [ ] App shell: auth, sidebar, command palette, locale switch
- [ ] Dashboard (KPIs, charts, attention queues)
- [ ] Users (list, detail, actions)
- [ ] Professor applications queue + decision
- [ ] Professors + KYC viewer
- [ ] Content review screen (diff, preview, approve/reject)
- [ ] Courses, categories (tree + ordering), featured
- [ ] Moderation (reports, reviews, Q&A)
- [ ] Orders, payments (timeline, recheck), refunds
- [ ] Coupons, bundles
- [ ] Finance: ledger explorer, adjustments, payout batches
- [ ] Settings, audit log, staff
- [ ] Playwright: admin approval journeys

### 2.3 Web app — public (apps/web) — PRD-04
- [ ] Layout, header/footer, locale routing, SEO base (metadata, sitemap, robots, hreflang)
- [ ] Home rails
- [ ] Category pages + filters
- [ ] Search page
- [ ] Course page (JSON-LD, preview playback)
- [ ] Professor page
- [ ] Certificate verification page
- [ ] "Teach on Yekkola" page (PRD-02)
- [ ] Legal pages
- [ ] Lighthouse CI budgets on public pages

### 2.4 Web app — students — PRD-01, 05, 06, 07
- [ ] Sign-in (phone + OTP, Turnstile)
- [ ] Account, devices, notification preferences, data export/deletion
- [ ] Free enroll
- [ ] Checkout: quote, coupon, rail picker, waiting screen, results, retry
- [ ] Orders + receipts, refund request
- [ ] My courses
- [ ] Learning view: Mux Player + watermark overlay, curriculum, progress
- [ ] Document viewer, quizzes, results
- [ ] Certificates, reviews, lesson Q&A
- [ ] Wishlist, in-app notifications
- [ ] Playwright: sign-in, free enroll, paid checkout (fake), learning

### 2.5 Web app — professor studio — PRD-02, 03, 08
- [ ] Application flow + status
- [ ] Onboarding checklist, profile, KYC upload, payout method
- [ ] Course list + course editor (details, curriculum DnD, pricing, review status)
- [ ] Mux uploader (resumable) + processing status
- [ ] Document upload, quiz builder
- [ ] Submit for review, version diff, unpublish
- [ ] Analytics dashboard
- [ ] Earnings, statement, payouts
- [ ] Reviews + Q&A inbox, coupons
- [ ] Playwright: create course → submit

---

## Phase 3 — Mobile (Flutter)

- [ ] Flutter app scaffold in `mobile/`, flavors (staging/production), CI `mobile.yml`
- [ ] Generated API client, Dio, auth + secure storage, `X-Device-Id`
- [ ] drift schema, outbox, sync engine, entitlement sync
- [ ] `mux_player_plugin`: Android (Kotlin, Media3, `MuxDownloadManager`) — prototype first
- [ ] `mux_player_plugin`: iOS (Swift, `MuxOfflineAccessManager`)
- [ ] Sign-in + device limit flow
- [ ] Catalogue, search, course and professor pages (offline cache)
- [ ] Free enroll + checkout (waiting screen)
- [ ] My courses, learning, player with overlay, `FLAG_SECURE`
- [ ] Downloads manager (quality, sizes, Wi-Fi only, storage, license expiry)
- [ ] Documents (stamped, app-private), quizzes (offline attempts)
- [ ] Certificates, reviews, Q&A
- [ ] Push (FCM) + local notifications, deep links
- [ ] Forced-update screen (`min_supported_version`)
- [ ] Testing on low-end Android (2 GB RAM) + iOS
- [ ] Store listings (FR/EN), privacy labels, submissions

---

## Phase 4 — Real integrations (deferred, required before launch)

- [ ] **(blocked: aggregator choice)** Payment aggregator: collection on Orange, Airtel, M-Pesa
- [ ] **(blocked: aggregator choice)** Disbursement for payouts
- [ ] Gateway driver + contract tests + sandbox end-to-end
- [ ] Operator-specific waiting-screen hints (PRD-05 §6)
- [ ] **(blocked: SMS gateway choice)** SMS gateway driver + DRC deliverability test + delivery reports
- [ ] Transactional email provider driver

---

## Phase 5 — Launch readiness

- [ ] Security review (OWASP ASVS L2 checklist), dependency audit
- [ ] Load test results within NFR targets
- [ ] Monitoring + alerts live (queues, payments, webhooks, OTP, Mux errors, latency)
- [ ] Backup restore drill on production-like data
- [ ] Runbooks written
- [ ] Legal pages published (ToS, privacy, professor agreement)
- [ ] Platform settings finalised in production
- [ ] Professor recruitment + first courses reviewed and live
- [ ] Support channel (WhatsApp/email) staffed
- [ ] Staging → production cutover checklist
- [ ] Public launch 🚀

## Post-launch backlog (not scheduled)

- Standalone purchasable practice exams (PRD-06 §10)
- Professor "resources" attachments on video lessons (PRD-03 §10)
- WhatsApp channel (if not done before launch)
- Captions/subtitles (PRD-06 §10)

---

## How to update

- Update this file **in the same branch/PR** as the work it tracks.
- Starting a task: mark `[~]` and set **Now → Current branch**.
- Finishing: mark `[x]` and add the PR number, e.g. `- [x] OTP request/verify (FR-01–03) — #12`.
- New work discovered: add it under the right phase (never silently).
- Decisions made with `/decide`: tick the item in 0.5 and note the outcome briefly.
- Dropped work: `[-]` with a one-line reason.
- Always bump **Last updated** and update **Now**.
