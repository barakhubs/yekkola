# Yekkola — Project TODO

> Single list of everything from start to launch. **Update it in the same PR as the work** (see _How to update_).
> Last updated: 2026-10-26

## Now

- **Current branch:** `feature/prd-09-admin-review` (web milestone 1 step 8: minimal back office)
- **Next up:** after milestone 1 — the rest of Phase 2 (student account, studio onboarding, back office)

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
- [x] Claude Code setup: settings, rules, skills, subagents, commands — #1
- [x] Git workflow + SOLID guidelines — #1
- [x] Project TODO list + autonomous workflow commands — #2
- [x] `.gitattributes` (LF line endings) — #3
- [x] Runbooks: stuck payments, failed payouts, Mux outage, SMS outage, compromised admin (`docs/runbooks/`) — #3

### 0.2 Brand

- [x] Brand colours: primary `#2c3892`, secondary `#fdb73b`
- [x] Full colour scales (50–950), dark-mode tints, contrast-checked (`docs/brand.md`) — #3
- [x] Typography: **Nunito** everywhere, type scale (`docs/brand.md`) — #3
- [x] Tagline _(provisional)_: _Les meilleurs profs du Congo, partout au Congo._
- [ ] Logo set — brief in `docs/brand.md` §4 **(needs: owner / designer)**
- [ ] Domain name(s) on one parent domain (`api.`, `www.`, `admin.`) **(needs: owner to purchase)**

### 0.3 Accounts & services — all **(needs: owner — account + billing)**

- [ ] Laravel Cloud organisation + `staging` and `production` environments
- [ ] Mux account (DRM enabled, signing keys, webhook secret)
- [ ] Meilisearch Cloud project
- [ ] Sentry projects (api, web, admin, mobile)
- [ ] PostHog (or equivalent) project
- [ ] Transactional email provider — provisional choice: Resend (Laravel mail driver) **(needs: account)**
- [ ] Firebase project (FCM) for push
- [ ] Google Play + Apple developer accounts (needed by phase 3)
- [-] GitHub branch protection — moved to Phase 1.1 (needs CI checks first)

### 0.4 Technical spikes

- [x] Mux research: DRM needs `plus` quality; **audio-only can't use DRM** → signed playback + encrypted offline files; download tiers; free tiers (`docs/research/mux.md`)
- [x] Mux cost estimate (`docs/research/mux.md` §4) — #3
- [x] Spike plans written (`docs/spikes/`) — #3
- [ ] Run Mux DRM + offline spike (`docs/spikes/mux-drm-offline.md`) **(needs: Mux account)**
- [ ] Run Next.js-on-Laravel-Cloud monorepo spike (`docs/spikes/laravel-cloud-nextjs.md`) **(needs: Laravel Cloud account)**
- [ ] Run region latency measurement → choose region (`docs/spikes/region-latency.md`) **(needs: RIPE Atlas credits or DRC testers)**

### 0.5 Business & legal decisions

Provisional defaults recorded in `project-context.md` → _Platform settings_ (confirm before launch — Phase 5):

- [x] Revenue split — 70% professor / 30% platform _(provisional)_
- [x] Payouts — monthly, minimum 10 USD / 25,000 CDF _(provisional)_
- [x] Currencies — USD + CDF enabled, USD default _(provisional)_
- [x] Launch categories — academic + professional tree (`docs/catalogue-categories.md`) _(provisional)_
- [x] Disbursement fees — platform pays _(provisional)_
- [x] Bundle with an owned course — exclude + pro-rata price _(provisional)_
- [x] Coupon funding — platform-funded keeps the professor's list-price share (capped at the price paid); professor-funded keeps the platform's; zero-priced lines earn nothing _(provisional)_
- [x] No coupon on top of a bundle _(provisional)_
- [x] Review SLAs — courses 48 h, applications 72 h _(provisional)_
- [x] WhatsApp channel — post-launch backlog _(provisional)_

Cannot be defaulted:

- [ ] Tax treatment (VAT on sales, withholding on payouts) **(needs: DRC accountant)**
- [ ] Legal entity; terms of service; privacy policy; professor agreement **(needs: counsel)**
- [ ] Minimum age + minors' data policy **(needs: counsel)**

---

## Phase 1 — Backend (API)

### 1.1 Scaffold & CI

- [x] Laravel 13 API-only app in `api/` — PHP 8.3+ (CI 8.3 + 8.4), PostgreSQL, Valkey — #4
- [x] Local services via `compose.yaml`: Postgres (port 55432), Valkey, Meilisearch; separate `yekkola_test` DB — #4
- [x] Packages: Sanctum, Horizon, Scout + Meilisearch, query-builder, permission, medialibrary, settings, translatable, activitylog, backup, Scramble, php-jwt, libphonenumber (`muxinc/mux-php` dropped — needs Guzzle 7; Mux via HTTP client + JWT) — #4
- [x] Pest, PHPStan level 6 (Larastan), Pint (strict types) + `composer check` — #4
- [x] Base structure: `app/Domain/*` (User moved to `Identity`, ULID ids, phone-first users table), `routes/api_v1.php` under `/api/v1`, error envelope with stable codes, global `SetLocaleFromHeader`, `idempotent` middleware, CORS for the two front ends, stricter Eloquent outside production — #4
- [x] `Money` and `PhoneNumber` value objects + tests — #4
- [x] `lang/fr` + `lang/en` (laravel-lang + `errors.php`) — #4
- [x] Scramble OpenAPI export to `api/openapi.json` (CI fails if stale) — #4
- [x] GitHub Actions: `api.yml` (Pint, PHPStan, Pest, OpenAPI drift) with path filters — #4
- [x] CLAUDE.md "Commands" sections filled in — #4
- [ ] Laravel Cloud `yekkola-api` app + staging deploy + queue workers **(needs: Laravel Cloud account)**
- [ ] GitHub branch protection requiring CI — needs an always-running summary job first (path-filtered workflows don't report on docs-only PRs); do with `contract.yml` in 2.1

### 1.2 Platform core

- [x] Settings classes (commerce, payouts, protection, catalog, learning, moderation) + seeded defaults — #5
- [x] Roles & permissions from enums (student, professor, moderator, admin, super-admin; `finance.*` granted individually) + seeder — #5
- [x] Provinces seed (26, ISO 3166-2:CD) + optional `users.province_id` — #5
- [x] Audit log: ULID entries, `RecordAudit` action, automatic before/after audit of settings changes — #5
- [x] Integration interfaces + fakes: `VideoProvider`/`FakeVideoProvider`, `PaymentGateway`/`FakeGateway` (scenarios by number suffix), `SmsSender`/`LogSmsSender` + contract tests — #5
- [x] Production boot guard against fake/log drivers — #5

### 1.3 Auth & identity — PRD-01

- [x] Users (phone-first, auth_epoch, deletion request), OTP challenges, devices, device-bound tokens, data exports tables — #6
- [x] `$user->phone` returns a `PhoneNumber` (column stays `phone_e164`) — #6
- [x] Mobile tokens expire after 90 days and are bound to a device (one token per device) — #6
- [x] OTP request/verify: HMAC-stored codes, 5-min expiry, 5 attempts, 60 s cooldown, 3/10 min per phone, 10/h per IP, Turnstile on web (FR-01–03, FR-10) — #6
- [x] Web session (Sanctum SPA) + mobile device-bound tokens (FR-05, FR-06) — #6
- [x] Profile, locale, province, provinces endpoint (FR-04) — #6
- [x] Device list/remove + limit with same-code replace flow (FR-07, FR-08) — #6
- [x] Change phone with OTP to the new number; other sessions/devices signed out (FR-09) — #6
- [x] Suspend/ban behaviour + "sign out everywhere" via auth_epoch (FR-15) — #6
- [x] Account deletion (14-day grace, anonymisation command) + data export (FR-11, FR-12) — #6
- [ ] Staff sessions expire after 12 h + optional TOTP for admins (FR-14, Should) — with the admin API (1.11)
- [ ] Mobile app attestation (Play Integrity / App Attest) on OTP requests — phase 3
- [ ] Enforce a registered device (`X-Device-Id` matches the token's device) on mobile routes — with offline licenses (1.9)
- [ ] Verify trusted-proxy / client-IP handling on Laravel Cloud so per-IP limits see real client IPs — first staging deploy
- [ ] Real SMS provider driver + delivery reports — phase 4

### 1.4 Professors — PRD-02

- [x] Applications: draft autosave, credentials (max 5, private disk), submit with agreement, needs-info loop, reapply cooldown, 30-day expiry (FR-01–03, FR-06) — #7
- [x] Staff review: queue + filters, start review, approve (profile + role + unique slug), reject, request info, audited, no self-review (FR-04, FR-05) — #7
- [x] Profiles + public page by slug, slug editable once, avatar (FR-05, FR-07, FR-12) — #7
- [x] KYC: front/back/selfie on private disk, one pending at a time, staff verify/reject, audited file streaming (FR-08, BR-04) — #7
- [x] Payout method confirmed by OTP to the account number; 48 h hold on change; number encrypted (FR-09) — #7
- [x] Terms acceptance versioning + re-acceptance gate on studio routes (FR-10) — #7
- [x] Suspend / reinstate, revenue-share override, payable rule (FR-11, BR-01–03) — #7
- [ ] Link application/profile subjects to catalogue categories — with 1.6
- [ ] Hide suspended professors' courses from catalogue and search — with 1.6
- [ ] Sample lesson upload (≤ 5 min) through Mux — now possible with the Mux driver; wire into the application form
- [ ] Notifications on application/KYC decisions — with 1.12
- [ ] Check payout number prefix matches the chosen rail — with the payment aggregator (phase 4)
- [ ] Product analytics events (PRD-02 §9 and other PRDs) — set up with PostHog in Phase 2

### 1.5 Authoring — PRD-03

- [x] Schema: categories (+ seed), courses, versions, sections, lessons (stable uid), media assets, quizzes, webhook inbox — #8
- [x] Course create + settings (category, language, level, audience, price/free with limits and CDF whole francs) (FR-01, FR-09, FR-15, FR-18) — #8
- [x] Draft details + cover; curriculum CRUD + full reorder; preview limit (FR-02, FR-03, FR-08) — #8
- [x] Mux driver (HTTP + RS256 JWTs): DRM video uploads with burned-in logo, signed audio with audio rendition, tokens, offline license, signed webhooks (FR-04, FR-05) — #8
- [x] Webhook inbox (dedupe, re-fetch from provider, `media` queue) + fake upload endpoint for staging — #8
- [x] Document lessons (PDF, private disk); quiz builder with answer validation (FR-06, FR-07) — #8
- [x] Submit checklist, submit / withdraw, staff approve / reject (locked, audited), start new draft from live (uids kept, media reused) (FR-10–13) — #8
- [x] Unpublish / republish / archive; delete only if never published (FR-16, FR-17) — #8
- [ ] Delete only with zero enrollments — with 1.8
- [ ] Virus scan + page count for documents; pre-signed document uploads (FR-06)
- [ ] Professor name in the burned-in watermark (needs per-professor overlay image) (FR-05)
- [ ] Clean up unreferenced Mux assets (BR-05)
- [ ] Studio "changes since live" view, student-view preview, download sizes per lesson (FR-14, FR-19, FR-20)
- [ ] Verify DRM + signed thumbnails policy and lowest `max_resolution_tier` in the Mux spike
- [ ] Request the Mux upload outside the draft lock (no HTTP call while the row is locked)
- [ ] Admin "changes since live": compare quiz/document content and positions, not lesson timestamps
- [ ] Separate, larger rate limit for authoring uploads (shared `uploads` limiter is 30/h)
- [ ] Clean up document files when a section is deleted or a draft copy rolls back
- [ ] Re-poll media assets still waiting for upload; test out-of-order webhooks

### 1.6 Catalogue & search — PRD-04

- [x] Categories API (visible tree, fr/en, course counts) (FR-02) — #9
- [x] Catalogue with filters + sorts; course page with curriculum outline and download sizes; professor page with courses (FR-02, FR-04, FR-05, FR-07, BR-01, BR-02, FR-12) — #9
- [x] Home rails + admin-ordered featured list (FR-01, BR-03) — #9
- [x] Meilisearch indexing (queued, after commit, reindex on professor/category changes) with database fallback (FR-03, FR-11) — #9
- [x] Wishlist (erased on deletion, in data export) (FR-08) — #9
- [x] Certificate verification endpoint (FR-09) — #15
- [x] Preview playback tokens without login (FR-06) — #17
- [ ] Popular rail by enrollments in the last 30 days — with 1.8
- [x] Web page cache expired by tag when a course or professor changes (`RevalidateWebPages` → apps/web `/api/revalidate`, HMAC) — #18
- [ ] Set `WEB_SERVER_KEY` and `WEB_REVALIDATE_SECRET` (same values in api and web) on staging/production
- [ ] Confirm download-size bitrates (`DOWNLOAD_ESTIMATE_*_KBPS`) against real Mux renditions
- [ ] Add `php artisan scout:sync-index-settings` to the Cloud deploy commands; check French typo tolerance on Meilisearch Cloud
- [ ] Search suggestions as you type (FR-03, Should)
- [x] Catalogue and certificate throttles key on the visitor IP forwarded by apps/web (`X-Web-Server-Key` + `X-Client-Ip`) — #18
- [x] Accent-insensitive database fallback (`unaccent`) — #18
- [ ] Confirm the Laravel Cloud Postgres user can `CREATE EXTENSION unaccent` (first staging deploy)
- [ ] Push filters into Meilisearch for very broad searches (> 1,000 hits)
- [ ] Keep `courses.enrollment_count` (and professor student counts) up to date on grant/revoke — nothing maintains it yet; the demo seeder sets it

### 1.7 Checkout & payments — PRD-05 (fake gateway)

_Four PRs: a) checkout core, b) coupons + bundles, c) parent payer, d) receipts + refunds._

- [x] a: Orders, items, payments, payment events, enrollments schema (arch §3.4, §3.5 enrollments) — #10
- [x] a: Ledger core pulled forward from 1.10 — accounts, append-only transactions/entries, sale + reversal postings (PRD-08 FR-01, FR-02) — #10
- [x] a: Free enroll (FR-01) — #10
- [x] a: Quote + order creation with idempotency + snapshots (FR-02–04) — round totals to `Currency::collectionStepMinor()` (CDF whole francs) — #10
- [x] a: Payment start, webhook inbox, re-query, state machine (FR-05–09) — log invalid webhook signatures at warning level; store events for unknown references and retry — #10
- [x] a: Reconciliation + expiry job (FR-10); rails availability (FR-19); order history (FR-17) — #10
- [x] b: Coupons (FR-11, FR-12) and bundles (FR-13), discount postings per funder (PRD-08 FR-02); `promotions.manage` permission — #11
- [x] c: Parent payer (FR-14) — signed-in buyer for another student, and guest checkout with an order token — #12
- [ ] Receipt SMS to the payer number as well as the student (PRD-10, on `OrderPaid`)
- [ ] Confirm buy-for-others limits (`config/yekkola.php` → `checkout`) and how long payer numbers are kept (counsel)
- [ ] Receipt SMS count against the global SMS budget (no SMS pumping through gift orders)
- [x] d: Receipts JSON + PDF, fr/en (FR-15) — #13
- [x] d: Refund request + approval flow + payout to the original payer (FR-16) — sale parts from `SaleSplit` — #13
- [x] d: Staff send back `unapplied_receipt` money — amount mismatch, duplicate payment, already owned — #13
- [ ] Reconciliation: one queued job per due payment once volume needs it
- [ ] VAT lines on receipts once tax treatment is decided (0.5)
- [ ] Confirm refund payout fees are always borne by the platform (payouts follow `platform_pays_disbursement_fees`)
- [ ] Staff tooling to recover `payer_receivable` debts (refund paid twice) — with the back office (2.x)
- [ ] Payment webhooks: release the job (don't mark processed) when the status API is down, as refunds now do

### 1.8 Learning — PRD-06

_Three PRs: a) progress + quizzes, b) certificates + reviews, c) Q&A + reports._

- [x] a: Progress and quiz-attempt schema (arch §3.5; enrollments landed in 1.7a) — #14
- [x] a: My courses + learning view endpoints (FR-01, FR-02, FR-09) — #14
- [x] a: Progress batch sync with monotonic merge; server-side completion rules (FR-04, FR-05) — #14
- [x] a: Quiz attempts: start/resume, submit, server timing with grace, attempts limit, corrections (FR-07) — #14
- [ ] Offline quiz attempts submitted on sync (FR-08) — with the mobile app
- [x] b: Certificates: rule, PDF with QR, serial, verification endpoint, revocation on refund/reversal (FR-10, FR-11, PRD-04 FR-09) — #15
- [x] b: Reviews + professor reply; course and professor ratings kept from published reviews (FR-12, BR-03, BR-04) — #15
- [ ] Notify students on certificate issued and professors on new reviews (`CertificateIssued`; reviews) — with 1.12
- [ ] Admin hide/restore reviews (`ReviewStatus::Hidden` exists) — with moderation in 1.11; the ban action must refresh ratings (`RefreshCourseRatings`) if it bypasses Eloquent `saved` events
- [ ] Cache rendered certificate PDFs per serial + locale if rendering shows up in p95
- [ ] c: Lesson Q&A threads (FR-13)
- [ ] c: Reports (FR-14)
- [ ] Course updated notice with changelog (FR-15)

### 1.9 Content protection — PRD-07

- [x] Playback + DRM token endpoint with checks, viewer watermark text (FR-02, FR-04) — #17
- [ ] Playback for mobile: require the registered device (`X-Device-Id`) and check account device limits (FR-06) — with offline licenses
- [x] Stream sessions + concurrent web limit (FR-05) — #17
- [ ] Offline license endpoint + `offline_licenses` (FR-07)
- [ ] Entitlement sync endpoint (FR-10)
- [x] Per-student PDF stamping + signed URLs (FR-12) — #17
- [ ] Free FPDI can't read PDFs with compressed cross-reference streams (many PDF 1.5+ exports); those uploads are refused with export advice. Evaluate the FPDI PDF-Parser licence or a qpdf/Ghostscript normaliser on Laravel Cloud **(needs: decision)**
- [ ] Clean up stamped copies when a document is replaced (old `document_stamps` rows go with the media row; their files stay)
- [ ] Check documents uploaded before the stamper check (`inspect`) and tell professors which ones must be re-exported
- [ ] Quiz attempts on preview lessons: also require the course to be listed (`CourseAccess::previewAllowed`, as playback and documents do)
- [ ] Shorter web playback tokens refreshed only for a live stream session, so the web stream limit doesn't rely on the player (FR-05)
- [ ] Takedown/revocation effects (FR-15)

### 1.9b Copy

- [ ] API messages to students use _vous_ (`lang/fr/errors.php`); the brand says _tu_ for students — align student-facing API copy in one pass

### 1.10 Finance — PRD-08

- [x] Ledger accounts/transactions/entries + posting service (FR-01) — core in 1.7a, #10
- [ ] Sale, release, refund postings (FR-02–05)
- [ ] Payout batches: build, approve, execute (fake disbursement), results (FR-06–08, FR-13)
- [ ] Payouts round CDF down to whole francs (`collectionStepMinor`) and carry the centimes forward (shares can hold centimes)
- [ ] Sale reversal and refunds after release: take the professor part from available (`PostOrderReversal`, `RefundPostings` TODOs) — required before payouts
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
- [ ] Settings + audit log + staff endpoints — settings update action validates invariants (bps 0–10000, limits per enabled currency, hold ≥ refund window, schedule enum) and wraps save in a transaction

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

### Web milestone 1 — client demo (in this order; the rest of Phase 2 comes after)

1. Foundations (2.1)
2. Minimal playback token + document URL endpoints (1.9 FR-01, FR-02, FR-12 slice) so lessons can be watched/read
3. Public site: layout, home rails, catalogue + search, course page, professor page (2.3)
4. Sign-in (phone + OTP), free enroll, My courses (2.4)
5. Learning view: curriculum, progress, player, documents, quizzes (2.4)
6. Paid checkout on the fake gateway: quote, rail picker, waiting screen, receipt (2.4)
7. Studio: course editor, curriculum, pricing, quiz builder, submit for review (2.5)
8. Back office, minimal: sign-in, professor applications, course version approval (2.2)

Needs from the owner for a hosted demo: Mux account (real playback) and Laravel Cloud account (staging) — 0.3.

### 2.1 Front-end foundations

- [x] pnpm workspace + Turborepo at repo root — #16
- [x] `packages/config` (tsconfig, ESLint, Prettier; Tailwind v4 theme lives in `packages/ui` CSS instead of a preset) — #16
- [x] `packages/ui`: shadcn/ui setup, brand tokens (light/dark), primitives, blocks (KpiCard, PageHeader, EmptyState, ConfirmDialog, StatusBadge, MoneyText) — #16
- [ ] `packages/ui`: DataTable (TanStack Table + nuqs), ChartCard, FileUpload — with the first screen that needs each (2.2 / 2.5)
- [x] `packages/i18n`: fr/en catalogues + money/date/phone/duration formatters — #16
- [x] `packages/api-client`: Orval config + generation script, fetcher (Sanctum cookies + XSRF, locale, error envelope) — #16
- [x] CI: `web.yml` (lint, typecheck, tests, build, audit) + `contract.yml` (client drift check) — #16
- [x] `apps/web` and `apps/admin` shells: locale routing (fr default at `/`), Nunito, providers, static home pages — #16
- [ ] Local Node is 22.11; Vite 8 / Rolldown need ≥ 22.12 (tests pin Vite 7) — upgrade Node, then drop the pin
- [ ] Laravel Cloud apps `yekkola-web` and `yekkola-admin` + staging deploys

### 2.2 Back office (apps/admin) — PRD-09 (before the student web app)

- [x] App shell: staff sign-in, sidebar by permission, locale switch, sign-out, shared `DataTable` — #PR
- [ ] Command palette (search users / courses / orders by ID or phone) and breadcrumbs
- [ ] Dashboard (KPIs, charts, attention queues)
- [ ] Users (list, detail, actions)
- [x] Professor applications queue + decision (start review, approve, request info, reject; credential files) — #PR
- [ ] Professors + KYC viewer
- [x] Content review screen: details, curriculum with quiz answers, changes since live, approve / reject with notes — #PR
- [ ] Lesson preview with the player in the review screen (needs an admin playback/document endpoint)
- [ ] Side-by-side diff of details (title, description, outcomes) vs the live version, not just lesson lists
- [ ] Courses, categories (tree + ordering), featured
- [ ] Moderation (reports, reviews, Q&A)
- [ ] Orders, payments (timeline, recheck), refunds
- [ ] Coupons, bundles
- [ ] Finance: ledger explorer, adjustments, payout batches
- [ ] Settings, audit log, staff
- [ ] Playwright: admin approval journeys

### 2.3 Web app — public (apps/web) — PRD-04

- [x] Layout, header/footer, locale routing, SEO base (metadata, sitemap, robots, hreflang, JSON-LD) — #18
- [x] Home rails + search + categories — #18
- [x] Category pages + filters (GET forms: no JS needed, state in the URL) — #18
- [x] Search page (`/courses?q=`) — #18
- [x] Course page (JSON-LD, preview playback with Mux Player loaded on demand, share, reviews) — #18
- [ ] Course page: buy/enrol button works (now links to sign-in) — with 2.4
- [ ] Course page: "more reviews" pagination (first 10 shown)
- [ ] Home: "by category" rails (PRD-04 FR-01; category chips for now)
- [x] Sign-in page: accept `?next=` only for same-origin paths starting with a single `/` (`safeNext`, unit-tested) — #19
- [ ] Show a "try again" state when the API rate-limits a public page (429) instead of the error page
- [x] Professor page — #18
- [x] Certificate verification page (noindex) — #18
- [ ] "Teach on Yekkola" page (PRD-02)
- [ ] Legal pages
- [ ] Lighthouse CI budgets on public pages

### 2.4 Web app — students — PRD-01, 05, 06, 07

- [x] Sign-in (phone + OTP, Turnstile, first-time name + optional province, safe `?next=`) — #19
- [ ] Account, devices, notification preferences, data export/deletion
- [x] Free enroll (course page button; paid courses show "payment coming soon" until checkout) — #19
- [x] Checkout on the fake gateway: quote, coupon, rail picker, payer number, waiting screen with countdown, results, retry with another operator or number — #21
- [x] Orders list + receipt PDF links — #21
- [ ] Refund request from the orders page (PRD-05 FR-12, API exists)
- [ ] Buy for another student / guest payer link in the web checkout (PRD-05 FR-14, API exists)
- [ ] Bundles in the web checkout (course page "bundle" offers, PRD-05 FR-11)
- [ ] Operator-specific hints on the waiting screen (USSD code, "check *144#") once the real aggregator is chosen
- [ ] Playwright journey: paid checkout on the fake gateway (success, insufficient funds `…0001` then retry)
- [x] My courses (progress, last activity, in progress / completed filter, paging); suspended accounts see an explanation (FR-15) — #19
- [ ] Sign-in: live countdown from `retry_after` on 429, support contact after 3 resends (PRD-01 §6–7); test resend with single-use bot tokens (Playwright)
- [ ] Light "am I enrolled" check for the course page (it uses the full learning view today)
- [ ] Header session check: avoid a GET /me on every public page view for visitors (e.g. a non-HttpOnly "signed-in" hint cookie)
- [x] Learning view: Mux Player + watermark overlay, curriculum, progress (saved every 15 s, on pause/end/leave), web stream limit — #20
- [x] Document viewer (stamped copy, inline + open), quizzes (timer, results, explanations, retake) — #20
- [x] Course completion: certificate link + review form (PRD-06 FR-16) — #20
- [ ] Lesson Q&A (with API 1.8c), certificates list page, edit/delete my review
- [ ] Learning view: refresh playback tokens before they expire on long sessions; keyboard shortcuts for next/previous
- [ ] Playwright journey: sign in → enrol free → watch preview → finish document + quiz → certificate
- [ ] Check on staging that the `keepalive` progress PUT on tab close arrives (Chrome, Safari, Firefox; it needs a CORS preflight); if not, save to localStorage on `pagehide` and send on the next visit
- [ ] Learning view: re-issue playback tokens on a player error after a very long pause
- [ ] Wishlist, in-app notifications
- [ ] Playwright: sign-in, free enroll, paid checkout (fake), learning

### 2.5 Web app — professor studio — PRD-02, 03, 08

- [ ] Application flow + status
- [ ] Onboarding checklist, profile, KYC upload, payout method
- [x] Course list + course editor (details, cover, category/level/audience, curriculum DnD with keyboard, pricing, status banner) — #22
- [x] Video/audio uploader (chunked, resumes offline, survives tab changes) + processing status — #22
- [x] Document upload, quiz builder — #22
- [x] Submit / withdraw, reviewer notes, start new draft, unpublish / republish / archive / delete — #22
- [ ] Version diff view “changes since live” (PRD-03 FR-14, needs an API diff endpoint)
- [ ] Course preview as a student from the studio (PRD-03 FR-19)
- [ ] Rich-text course description (PRD-03 FR-02 asks for rich text; plain text today) and quiz bulk paste (§6, Should)
- [ ] Video/audio size limit is only checked in the browser: Mux direct uploads can't be capped by size. Decide a server-side guard (e.g. a max duration setting checked on `video.asset.ready`, asset errored + deleted when over) — PRD-03 FR-04 **(decision)**
- [ ] Replacing a ready video/audio: keep the previous asset until the new one is ready (today the lesson points at the new asset at once, so a cancelled or failed replacement leaves it empty)
- [ ] Sync `waiting_upload` assets with the provider (Mux "get upload" → asset id) when the `upload.asset_created` webhook is missed; needs `VideoProvider::getUpload`
- [ ] True/false quiz options in the course's language (today the professor's UI language), ideally generated by the API
- [ ] Show estimated download sizes per lesson in the studio (PRD-03 FR-20)
- [ ] Expose authoring validation constants (outcomes 3–8, cover size, audiences 4, min lessons) in `/professor/authoring-options` so copy doesn't restate them
- [ ] Favicon / app icons (404 on /favicon.ico) — with the logo set (0.2)
- [ ] Analytics dashboard
- [ ] Earnings, statement, payouts
- [ ] Reviews + Q&A inbox, coupons
- [ ] Playwright: create course → submit (a local script covered it for #22: details, cover, chunked video, PDF, quiz, keyboard reorder, price, submit)

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
- [ ] Confirm all _(provisional)_ defaults (Phase 0.5, tagline, email provider, OTP country allowlist) and finalise platform settings in production
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
