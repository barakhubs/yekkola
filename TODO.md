# Yekkola — Project TODO

> Single list of everything from start to launch. **Update it in the same PR as the work** (see _How to update_).
> Last updated: 2026-11-09

## Now

- **Current branch:** `chore/web-polish` (more reviews, error page, sign-in countdown, sitemap bundles, studio download sizes, coupon currencies)
- **Next up:** Phase 3 (mobile)

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
- [x] Runbooks: stuck payments, failed payouts, Mux outage (now R2/transcoding outage), SMS outage, compromised admin (`docs/runbooks/`) — #3

### 0.2 Brand

- [x] Brand colours: primary `#2c3892`, secondary `#fdb73b`
- [x] Full colour scales (50–950), dark-mode tints, contrast-checked (`docs/brand.md`) — #3
- [x] Typography: **Nunito** everywhere, type scale (`docs/brand.md`) — #3
- [x] Tagline _(provisional)_: _Les meilleurs profs du Congo, partout au Congo._
- [ ] Logo set — brief in `docs/brand.md` §4 **(needs: owner / designer)**
- [ ] Domain name(s) on one parent domain (`api.`, `www.`, `admin.`) **(needs: owner to purchase)**

### 0.3 Accounts & services — all **(needs: owner — account + billing)**

- [ ] Laravel Cloud organisation + `staging` and `production` environments
- [-] Mux account (DRM enabled, signing keys, webhook secret) — superseded by R2, 2026-11-09
- [ ] Cloudflare account: R2 buckets per environment (private + public), CORS for browser uploads from the web origins, access keys per environment — steps in `docs/runbooks/deploy-laravel-cloud.md` ("Files and media on Cloudflare R2"); then re-upload brand images, avatars and covers stored on the API's local disk
- [ ] Meilisearch Cloud project
- [ ] Sentry projects (api, web, admin, mobile)
- [ ] PostHog (or equivalent) project
- [ ] Transactional email provider — provisional choice: Resend (Laravel mail driver) **(needs: account)**
- [ ] Firebase project (FCM) for push
- [ ] Google Play + Apple developer accounts (needed by phase 3)
- [-] GitHub branch protection — moved to Phase 1.1 (needs CI checks first)

### 0.4 Technical spikes

- [x] Mux research: DRM needs `plus` quality; **audio-only can't use DRM** → signed playback + encrypted offline files; download tiers; free tiers (`docs/research/mux.md`, now superseded by R2, 2026-11-09)
- [x] Mux cost estimate (`docs/research/mux.md` §4) — #3 (superseded by R2, 2026-11-09)
- [ ] R2 + FFmpeg cost estimate (storage, transcoding workers, API playlist/key traffic) to replace `docs/research/mux.md`
- [x] Spike plans written (`docs/spikes/`) — #3
- [-] Run Mux DRM + offline spike (`docs/spikes/mux-drm-offline.md`) — superseded by R2, 2026-11-09
- [ ] Run R2 media spike (`docs/spikes/r2-hls-media.md`): FFmpeg availability, CPU and memory on Laravel Cloud queue workers (else a dedicated transcoding worker or container); transcoding time per media hour and `media` queue sizing **(needs: Laravel Cloud + Cloudflare accounts)**
- [ ] Same spike: AES-128 HLS playback and offline download on low-end Android (2 GB RAM, Android 8+) and iOS, and in the web player (hls.js) on low-end phones
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
- [x] Media hosting — all files and media on Cloudflare R2, encrypted HLS (AES-128) instead of Mux DRM; weaker protection accepted (owner, 2026-11-09)

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
- [x] Staff email + password sign-in, default super admin (`admin:create-super-admin`), staff managers set passwords, forced change — #44 (owner decision, 2026-10-31)
- [x] Super-admin impersonation + demo users per role (local/staging) — #45
- [ ] Staff password reset by email, once an email provider is chosen
- [ ] Staff sessions expire after 12 h + optional TOTP for admins (FR-14, Should) — with the admin API (1.11)
- [ ] Mobile app attestation (Play Integrity / App Attest) on OTP requests — phase 3
- [ ] Enforce a registered device (`X-Device-Id` matches the token's device) on mobile routes — with offline licenses (1.9)
- [ ] Verify trusted-proxy / client-IP handling on Laravel Cloud so per-IP limits see real client IPs (now also the per-visitor baseline on every public route: if `request->ip()` is the edge, trust only the Cloud edge, never `*`) — first staging deploy
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
- [ ] Sample lesson upload (≤ 5 min) through the video driver (pre-signed R2 upload once 1.5b lands); wire into the application form
- [ ] Notifications on application/KYC decisions — with 1.12 (the web status page says to check back until then)
- [ ] Check payout number prefix matches the chosen rail — with the payment aggregator (phase 4)
- [ ] Product analytics events (PRD-02 §9 and other PRDs) — set up with PostHog in Phase 2

### 1.5 Authoring — PRD-03

- [x] Schema: categories (+ seed), courses, versions, sections, lessons (stable uid), media assets, quizzes, webhook inbox — #8
- [x] Course create + settings (category, language, level, audience, price/free with limits and CDF whole francs) (FR-01, FR-09, FR-15, FR-18) — #8
- [x] Draft details + cover; curriculum CRUD + full reorder; preview limit (FR-02, FR-03, FR-08) — #8
- [x] Mux driver (HTTP + RS256 JWTs): DRM video uploads with burned-in logo, signed audio with audio rendition, tokens, offline license, signed webhooks (FR-04, FR-05) — #8 (removed for R2 in #61)
- [x] Webhook inbox (dedupe, re-fetch from provider, `media` queue) + fake upload endpoint for staging — #8
- [x] Document lessons (PDF, private disk); quiz builder with answer validation (FR-06, FR-07) — #8
- [x] Submit checklist, submit / withdraw, staff approve / reject (locked, audited), start new draft from live (uids kept, media reused) (FR-10–13) — #8
- [x] Unpublish / republish / archive; delete only if never published (FR-16, FR-17) — #8
- [ ] Delete only with zero enrollments — with 1.8
- [ ] Virus scan + page count for documents; pre-signed document uploads (FR-06)
- [x] Professor name in the burned-in watermark: drawn by FFmpeg during transcoding ("Yekkola · name" + optional logo PNG) (FR-05) — #61
- [ ] Clean up unreferenced media assets: R2 source, playlists, segments and key (BR-05)
- [ ] Studio "changes since live" view, student-view preview, download sizes per lesson (FR-14, FR-19, FR-20)
- [-] Verify DRM + signed thumbnails policy and lowest `max_resolution_tier` in the Mux spike — superseded by R2, 2026-11-09 (no DRM; the rendition ladder and poster frames are ours)
- [-] Request the Mux upload outside the draft lock (no HTTP call while the row is locked) — superseded by R2, 2026-11-09 (one short R2 call, opening the multipart upload, stays inside the lock; pre-signing part URLs makes no HTTP call)
- [ ] Admin "changes since live": compare quiz/document content and positions, not lesson timestamps
- [ ] Separate, larger rate limit for authoring uploads (shared `uploads` limiter is 30/h)
- [ ] Clean up document files when a section is deleted or a draft copy rolls back
- [ ] Expire media assets never confirmed by upload-complete and delete their R2 uploads (out-of-order webhooks go away with Mux)

### 1.5b Media on Cloudflare R2 — owner decision 2026-11-09 (PRD-03, PRD-07)

- [x] R2 Storage disks `r2-private` / `r2-public` (S3 API, `R2_*` env) behind `yekkola.private_disk` / `yekkola.public_disk` / `yekkola.media.disk`; KYC still streamed through the API only — #61
- [ ] Staging/production env: `PRIVATE_DISK=r2-private`, `PUBLIC_DISK=r2-public`, `VIDEO_DRIVER=r2`, `R2_*`, `FFMPEG_BINARY`/`FFPROBE_BINARY`; `media` queue `retry_after` longer than `MEDIA_TRANSCODE_TIMEOUT` (3 h) + 10 min; private bucket CORS: `PUT` from the web origin with `ETag` exposed, and `GET`/`HEAD` from the web origins (segments); `MEDIA_WORKERS` for the `supervisor-media` Horizon supervisor
- [ ] Burned-in watermark assets on the workers: logo PNG at `resources/media/watermark.png` (or `MEDIA_WATERMARK_LOGO`) and a TTF for the text (`MEDIA_WATERMARK_FONT`, e.g. Nunito Bold)
- [x] R2 `VideoProvider` driver: multipart upload with pre-signed part URLs (`/media/{id}/parts`), `POST /professor/media/{id}/complete` (ETags, stored size re-checked against the limit), `ProcessMediaAsset` + `FfmpegTranscoder` on the `media` queue (720p/480p/360p never upscaled, mono audio HLS, AES-128 one key per asset, burned-in watermark, poster), `ready`/`errored` (arch §1.3 B) — #61
- [ ] Sweep stale `/tmp/yekkola-media-*` work folders on media workers (a hard-killed transcode leaves the source and key file behind)
- [ ] Delete the R2 source once an asset is `ready` (kept for now in case of re-transcoding; decide with the cost estimate)
- [x] `media_assets` schema: source key, size, output prefix, renditions, AES key (encrypted cast, never in R2), poster flag (arch §3.3) — #61
- [x] Playlist + key endpoints: `GET /lessons/{uid}/hls/master.m3u8`, `/hls/{rendition}.m3u8` (segments → pre-signed R2 URLs, signed playlists cached) and `/hls/key`, each re-checking access (`AuthorizeMedia`); `POST /lessons/{uid}/playback` returns `playlist_url` + `poster_url` (arch §1.3 D, §4.2) — #61
- [ ] Offline license endpoint returns rendition playlist + AES key + expiry (arch §1.3 E) — with 1.9 FR-07
- [x] Remove the Mux driver, `/webhooks/video`, the video webhook inbox use and Mux env vars; `FakeVideoProvider` reworked for parts; contract tests updated — #61
- [x] Check the R2/transcoding outage runbook (`docs/runbooks/media-outage.md`) against the driver (job name, cached signed playlists, retries) — #61

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
- [ ] Confirm download-size bitrates (`DOWNLOAD_ESTIMATE_*_KBPS`) against our real HLS renditions (or use the stored rendition sizes once the R2 driver records them)
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
- [x] Notify students on certificate issued and professors on new reviews — 1.12
- [x] Admin hide/restore reviews — #46
- [ ] Cache rendered certificate PDFs per serial + locale if rendering shows up in p95
- [x] c: Lesson Q&A threads: ask, reply, professor answer, inbox (FR-13) — #54
- [x] Notify the professor of a new question and the student of an answer (FR-13) — with 1.12
- [ ] Let authors edit or delete their own question or reply
- [ ] Q&A cached for offline reading and posting on mobile (PRD-06 §6) — with the mobile app
- [x] c: Reports on courses, reviews, professors (FR-14) — #52
- [x] Reports on Q&A threads and replies — #54
- [x] Tell a warned author about the warning (with 1.12)
- [ ] Tell reporters that their report was handled
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
- [x] Playlist and key requests check the live web stream session, so the web stream limit doesn't rely on the player (FR-05) — #61
- [x] Takedown: no new playback/document tokens for anyone — #42; offline removal at next sync comes with entitlement sync

### 1.9b Copy

- [ ] API messages to students use _vous_ (`lang/fr/errors.php`); the brand says _tu_ for students — align student-facing API copy in one pass

### 1.10 Finance — PRD-08

- [x] Ledger accounts/transactions/entries + posting service (FR-01) — core in 1.7a, #10
- [x] Sale, release, refund postings (FR-02–05) — release job + refunds from available after release, #32
- [x] Payout batches: build, approve, execute (fake disbursement), results (FR-06–08, FR-13) — #33
- [ ] Second approver above a threshold for payout batches (PRD-08 FR-07, Should)
- [ ] If professors bear disbursement fees (setting off): size each payout so balance − fee stays ≥ 0 (needs the aggregator's fee schedule)
- [x] Back-office payout batch screens — #35
- [x] Payouts round CDF down to whole francs (`collectionStepMinor`) and carry the centimes forward — #33
- [x] Sale reversal and refunds after release: take the professor part from available — #32
- [x] Earnings + statement endpoints, CSV (FR-09) — #34
- [ ] Monthly statement PDF (FR-10, Should)
- [x] Manual adjustments (FR-11) — #34
- [x] Ledger integrity job (FR-12) — #34; alert routing with 1.12

### 1.11 Admin API — PRD-09

- [x] Dashboard stats + `platform_daily_stats` rollup — #41
- [x] `course_daily_stats` rollup, hourly (professor analytics) — #64
- [ ] Course page views in analytics: needs view tracking first (PostHog or a counted event)
- [ ] Analytics: confirm that a transferred course shows its whole sales history to the new owner, and whether revenue should net out refunds (today: before refunds, labelled)
- [x] Professor applications, professors, KYC endpoints — #7; staff-typed applications with `can` — #23
- [x] Users endpoints (list, detail, suspend/ban, sign out everywhere, remove device) — #38
- [x] Grant an enrollment from the back office (PRD-09 FR-04) — #63
- [ ] Notify the account when it is suspended or banned (with 1.12)
- [x] Content review queue + lesson-level changes since live — #8; typed detail with `can` — #23
- [x] Courses (list, unpublish/republish), featured, takedown/restore — #42
- [x] Categories CRUD + ordering — #43
- [x] Transfer course ownership (super admin, PRD-09 FR-08) — #63
- [ ] Tell both professors about a course transfer (with notifications)
- [ ] Confirm: a transfer clears the course-level revenue share (provisional default; the new owner's rate applies) **(needs: owner decision)**
- [ ] Media shared between a transferred course and another of the old professor's courses stays theirs: block deleting an asset still used by any lesson when media cleanup lands (1.5)
- [x] Tell the professor about a takedown — 1.12
- [x] Review moderation — #46
- [x] Reports queue: dismiss, warn, hide/take down, suspend; one decision closes the item's open reports — #52
- [x] Q&A threads moderation: hide/restore questions and replies — #54
- [x] Orders, payments (recheck), refunds — #35, #37
- [x] Coupons, bundles — #11; names, filters and separate update bodies for the back office — this PR
- [x] Ledger explorer, adjustments, payout batches — #33, #34, #36
- [x] Settings endpoints — invariants checked across groups, one transaction, audited — #39
- [x] Brand image endpoints (upload/remove per asset, public `GET /branding`) — #53
- [x] Audit log + staff endpoints — #40

### 1.12 Notifications — PRD-10

- [x] Notification per catalogue event, fr/en texts, in-app + email + SMS from the `notifications` queue (FR-01, FR-02) — #56
- [x] In-app feed + preferences; web bell and notification centre (FR-03, FR-05) — #56
- [ ] Choose the transactional email provider (Postmark/Resend/SES) and verify the sending domain **(needs: decision)** — email uses `MAIL_MAILER=log` until then
- [ ] Laravel Cloud queue workers must also listen on `notifications`
- [x] Push: `PushSender` (FCM HTTP v1 + log driver), active devices, dead tokens forgotten, optional pushes capped 5/day (FR-04, FR-10) — #57
- [ ] Firebase project + service account (FCM_*), `PUSH_DRIVER=fcm` on staging/production **(needs: Firebase account)**
- [ ] Endpoint for the mobile app to refresh its push token (today set at sign-in) — with the mobile app
- [ ] Quiet hours 21:00–07:00 for optional pushes (FR-04, Should)
- [ ] Keep the professor digest's once-a-day guard in the database, not only the cache (a cache flush allows a second send)
- [x] Email channel + professor daily digest (FR-06) — #57
- [x] Staff alerts by permission: ledger check, failed payout batch, stuck payments; back-office bell and alerts page (FR-09) — #57

### 1.13 Backend hardening

- [x] OpenAPI spec published as a CI artifact (`openapi-spec`, from the PHP 8.4 job) — this PR
- [x] Rate limits on all sensitive endpoints: baseline on every route (600/min per account, per visitor otherwise), plus account deletion, impersonation, staff management, identity/credential files, CSV/export downloads; a test fails if a route has none — this PR
- [x] k6 load tests: catalogue, playback token, progress sync, checkout (`loadtest/`, students from `loadtest:prepare`) — this PR
- [ ] Run the k6 load tests on staging and record p95s against the targets **(needs: staging with the demo catalogue)**
- [ ] Backups configured + restore test on staging **(needs: Laravel Cloud staging database + backup bucket)**

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

Needs from the owner for a hosted demo: Cloudflare R2 buckets (real playback) and Laravel Cloud account (staging) — 0.3.

### 2.1 Front-end foundations

- [x] pnpm workspace + Turborepo at repo root — #16
- [x] `packages/config` (tsconfig, ESLint, Prettier; Tailwind v4 theme lives in `packages/ui` CSS instead of a preset) — #16
- [x] `packages/ui`: shadcn/ui setup, brand tokens (light/dark), primitives, blocks (KpiCard, PageHeader, EmptyState, ConfirmDialog, StatusBadge, MoneyText) — #16
- [x] `packages/ui`: DataTable (TanStack Table v9, server paging/sorting; apps keep state in the URL with nuqs) — #23
- [ ] `packages/ui`: ChartCard, FileUpload — with the first screen that needs each (dashboard, KYC)
- [x] `packages/i18n`: fr/en catalogues + money/date/phone/duration formatters — #16
- [x] `packages/api-client`: Orval config + generation script, fetcher (Sanctum cookies + XSRF, locale, error envelope) — #16
- [x] CI: `web.yml` (lint, typecheck, tests, build, audit) + `contract.yml` (client drift check) — #16
- [x] `apps/web` and `apps/admin` shells: locale routing (fr default at `/`), Nunito, providers, static home pages — #16
- [ ] Local Node is 22.11; Vite 8 / Rolldown need ≥ 22.12 (tests pin Vite 7) — upgrade Node, then drop the pin
- [~] Laravel Cloud apps `yekkola-web` and `yekkola-admin` + staging deploys — standalone builds + lockfile links so both deploy from their folders (#47); settings in `docs/runbooks/deploy-laravel-cloud.md`; `/up` health check (#48); servers listen on IPv4 + IPv6 for Cloud's probes (#49); web build times out API calls after 10 s instead of hanging (#50); empty `API_INTERNAL_URL` / site URL treated as unset
- [ ] Shared Next.js cache handler (Valkey) before running several web replicas

### 2.2 Back office (apps/admin) — PRD-09 (before the student web app)

- [x] App shell: staff sign-in, sidebar by permission, locale switch, sign-out, shared `DataTable` — #23
- [ ] Command palette (search users / courses / orders by ID or phone) and breadcrumbs
- [x] Dashboard (KPIs, daily bars, attention queues) — #41; richer charts with `ChartCard` (Recharts) later
- [x] Users (list, detail, actions) — #38
- [x] Professor applications queue + decision (start review, approve, request info, reject; credential files) — #23
- [x] Professors (list, detail, suspend/reinstate, revenue share) + identity-check queue and viewer (audited file links, verify/reject) — #62
- [x] Content review screen: details, curriculum with quiz answers, changes since live, approve / reject with notes — #23
- [x] Lesson preview in the review screen: staff HLS player + original document (PRD-09 FR-07) — #63
- [ ] Side-by-side diff of details (title, description, outcomes) vs the live version, not just lesson lists
- [x] Courses + featured — #42
- [x] Categories (tree + ordering) — #43
- [x] Review moderation screen — #46
- [x] Reports screen + dashboard queue — #52
- [x] Q&A moderation screen — #54
- [x] Orders, payments (timeline, recheck), refunds — #35, #37
- [x] Coupons (create platform-funded, limits, deactivate) and bundles (create, edit, on/off sale) — this PR
- [x] Coupon form offers the enabled currencies, default first (`GET /admin/coupons/options`) — #66
- [x] Finance: ledger explorer, adjustments, payout batches — #35, #36
- [x] Settings screen — #39
- [x] Settings in tabs (open tab in the URL) + Branding tab: logo, footer logo, favicon, share image — #53
- [ ] Real logo and favicon files from the logo brief (`docs/brand.md`), uploaded once designed
- [x] Audit log, staff — #40
- [ ] Playwright: admin approval journeys

### 2.3 Web app — public (apps/web) — PRD-04

- [x] Layout, header/footer, locale routing, SEO base (metadata, sitemap, robots, hreflang, JSON-LD) — #18
- [x] Home rails + search + categories — #18
- [x] Category pages + filters (GET forms: no JS needed, state in the URL) — #18
- [x] Search page (`/courses?q=`) — #18
- [x] Course page (JSON-LD, preview playback with Mux Player loaded on demand, share, reviews) — #18
- [x] Course page: buy/enrol button works (free enrol, paid → checkout) — #19, #21
- [x] Course page: "more reviews" (first 10 server-rendered, the rest on demand) — #66
- [ ] Home: "by category" rails (PRD-04 FR-01; category chips for now)
- [x] Sign-in page: accept `?next=` only for same-origin paths starting with a single `/` (`safeNext`, unit-tested) — #19
- [x] Error page with a short wait and "try again" (rate limits and API hiccups) — #66
- [x] Professor page — #18
- [x] Certificate verification page (noindex) — #18
- [x] "Teach on Yekkola" page (`/teach`, in the sitemap and footer) — #25
- [~] Legal pages: placeholders with a plain-language summary, `noindex`, footer links — #62; replace with counsel's texts before launch
- [ ] Lighthouse CI budgets on public pages

### 2.4 Web app — students — PRD-01, 05, 06, 07

- [x] Sign-in (phone + OTP, Turnstile, first-time name + optional province, safe `?next=`) — #19
- [x] Account page: profile, phone change, devices, data export, deletion — #28
- [x] Notification preferences page — #56
- [x] Free enroll (course page button; paid courses show "payment coming soon" until checkout) — #19
- [x] Checkout on the fake gateway: quote, coupon, rail picker, payer number, waiting screen with countdown, results, retry with another operator or number — #21
- [x] Orders list + receipt PDF links — #21
- [x] Refund request from the orders page (PRD-05 FR-16) — #29
- [x] Buy for another student in the signed-in checkout (PRD-05 FR-14) — #29
- [x] Guest checkout: a parent without an account pays for a student (`/courses/[slug]/gift`, token in the link after `#`; PRD-05 FR-14) — #30
- [ ] Guest receipt download (the API needs the order token header; a plain link can't send it)
- [ ] Gift a course the buyer already owns (the quote is for the buyer, so checkout currently stops at "already owned")
- [x] Bundles: bundle page, "also in these bundles" on course pages, checkout with `?bundle=` (PRD-05 FR-13) — #31
- [x] Bundle pages in the sitemap (public `GET /bundles`) — #66
- [ ] Operator-specific hints on the waiting screen (USSD code, "check *144#") once the real aggregator is chosen
- [ ] Playwright journey: paid checkout on the fake gateway (success, insufficient funds `…0001` then retry)
- [x] My courses (progress, last activity, in progress / completed filter, paging); suspended accounts see an explanation (FR-15) — #19
- [x] Sign-in: countdown from `retry_after` on 429, help after 3 codes (support contact from `NEXT_PUBLIC_SUPPORT_CONTACT`) (PRD-01 §6–7) — #66
- [ ] Choose the support contact (WhatsApp number or email) and set `NEXT_PUBLIC_SUPPORT_CONTACT` **(needs: owner)**
- [ ] Light "am I enrolled" check for the course page (it uses the full learning view today)
- [ ] Header session check: avoid a GET /me on every public page view for visitors (e.g. a non-HttpOnly "signed-in" hint cookie)
- [x] Learning view: Mux Player + watermark overlay, curriculum, progress (saved every 15 s, on pause/end/leave), web stream limit — #20
- [x] Document viewer (stamped copy, inline + open), quizzes (timer, results, explanations, retake) — #20
- [x] Course completion: certificate link + review form (PRD-06 FR-16) — #20
- [x] Certificates list page, edit/delete my review (completion screen) — #62; lesson Q&A done earlier
- [ ] Learning view: refresh playback tokens before they expire on long sessions; keyboard shortcuts for next/previous
- [ ] Playwright journey: sign in → enrol free → watch preview → finish document + quiz → certificate
- [ ] Check on staging that the `keepalive` progress PUT on tab close arrives (Chrome, Safari, Firefox; it needs a CORS preflight); if not, save to localStorage on `pagehide` and send on the next visit
- [ ] Learning view: re-issue playback tokens on a player error after a very long pause
- [x] Player swap: Mux Player → hls.js on a native video/audio element (loaded on demand; Safari native), credentials only to the API, playlist reload on expired segments, keeping `WatermarkOverlay` and the stream heartbeat; `@mux/*` dropped (PRD-06, PRD-07) — #61
- [ ] Player: own controls (speed menu, quality choice, keyboard shortcuts) if native controls fall short on low-end phones — check in the R2 spike
- [x] Wishlist page + save button on the course page; in-app notifications (#56) — #62
- [ ] Playwright: sign-in, free enroll, paid checkout (fake), learning

### 2.5 Web app — professor studio — PRD-02, 03, 08

- [x] Application flow + status: four steps with autosave, credential files, sample link, agreement, submitted / needs info / approved / rejected (with reapply date) / expired — #25
- [x] Onboarding checklist (profile, identity, payout method, first course), public profile editor, KYC upload, payout method with SMS code, agreement re-acceptance gate — #26
- [x] Course list + course editor (details, cover, category/level/audience, curriculum DnD with keyboard, pricing, status banner) — #22
- [x] Video/audio uploader (chunked, resumes offline, survives tab changes) + processing status — #22
- [x] Document upload, quiz builder — #22
- [x] Submit / withdraw, reviewer notes, start new draft, unpublish / republish / archive / delete — #22
- [ ] Version diff view “changes since live” (PRD-03 FR-14, needs an API diff endpoint)
- [ ] Course preview as a student from the studio (PRD-03 FR-19)
- [ ] Rich-text course description (PRD-03 FR-02 asks for rich text; plain text today) and quiz bulk paste (§6, Should)
- [-] Video/audio size limit is only checked in the browser: Mux direct uploads can't be capped by size — superseded by R2, 2026-11-09 (upload-complete checks the object size and the transcoder the duration, server-side)
- [ ] Replacing a ready video/audio: keep the previous asset until the new one is ready (today the lesson points at the new asset at once, so a cancelled or failed replacement leaves it empty)
- [-] Sync `waiting_upload` assets with the provider (Mux "get upload" → asset id) when the `upload.asset_created` webhook is missed — superseded by R2, 2026-11-09 (no upload webhooks)
- [x] Studio uploader: multipart upload to pre-signed R2 URLs (3 parts at a time, retries with backoff, waits while offline, fresh URL on expiry), then the upload-complete call (PRD-03 FR-04) — #61
- [ ] Studio uploader: resume after a page reload (keep the media id + sent parts; R2 keeps the multipart upload open)
- [ ] True/false quiz options in the course's language (today the professor's UI language), ideally generated by the API
- [x] Show estimated download sizes per lesson in the studio (PRD-03 FR-20) — #66
- [ ] Expose authoring validation constants (outcomes 3–8, cover size, audiences 4, min lessons) in `/professor/authoring-options` so copy doesn't restate them
- [ ] Favicon / app icons (404 on /favicon.ico) — with the logo set (0.2)
- [x] Analytics dashboard: period choice, comparison with the period before, daily bars, per course with student funnel, quiz pass rate — #64
- [x] Earnings, statement (filters, CSV), payout history — #62
- [x] Coupons and bundles (own courses, professor-funded) — #62
- [x] Reviews inbox: filters (reply, rating, course), write / edit / remove my public reply — #65
- [ ] Wishlist heart reads the first 100 saved courses: add a per-course `is_wishlisted` (course page) if lists grow past that
- [ ] Playwright: create course → submit (a local script covered it for #22: details, cover, chunked video, PDF, quiz, keyboard reorder, price, submit)

---

## Phase 3 — Mobile (Flutter)

- [ ] Flutter app scaffold in `mobile/`, flavors (staging/production), CI `mobile.yml`
- [ ] Generated API client, Dio, auth + secure storage, `X-Device-Id`
- [ ] drift schema, outbox, sync engine, entitlement sync
- [-] `mux_player_plugin`: Android (Kotlin, Media3, `MuxDownloadManager`) — superseded by R2, 2026-11-09 (no native-SDK plugin needed)
- [-] `mux_player_plugin`: iOS (Swift, `MuxOfflineAccessManager`) — superseded by R2, 2026-11-09
- [ ] Offline encrypted HLS: own downloader for a rendition's segments (resumable, background), AES key wrapped with a per-device key (Keystore / Keychain) with the offline expiry, standard Flutter player with AES-128 HLS + overlay; delete revoked content at sync — prototype early (PRD-07)
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
- [ ] Monitoring + alerts live (queues, payments, webhooks, OTP, transcoding failures, latency)
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
