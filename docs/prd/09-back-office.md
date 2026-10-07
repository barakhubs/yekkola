# PRD-09 — Back Office (Admin & Moderation)

| | |
|---|---|
| Status | Draft |
| Phase | 1 (admin API), 2 (apps/admin — built **before** the student web app) |
| Related | All PRDs; architecture §4.2 (Admin), §5.3 |

## 1. Summary

A React (Next.js + shadcn/ui) back office for admins, moderators, and finance staff to run the marketplace: vet professors, review course versions, moderate content, manage users, oversee orders/payments/refunds, run payouts, configure platform settings, and audit everything. Modern dashboards and data tables with server-side filtering are the core UI pattern.

## 2. Goals & non-goals

**Goals**
- Every operational task is doable without database access.
- Queues that need attention are visible from the dashboard.
- Every sensitive action is permissioned and audited.

**Non-goals**
- Customer-facing support ticketing system (support uses WhatsApp/email at launch; back office shows user context for support).
- BI/data warehouse — dashboards use pre-aggregated stats.

## 3. Roles & permissions

| Role | Can |
|---|---|
| Moderator | Applications, course reviews, reports, reviews/Q&A moderation, read-only users & courses |
| Admin | Everything except finance-only actions; settings; staff management |
| Finance (permission set, combinable) | Orders/payments detail, refunds, ledger, adjustments, payout batches |
| Super admin | Staff roles, destructive actions (takedown with deletion) |

## 4. Functional requirements

| ID | Requirement | Priority |
|---|---|---|
| FR-01 | **Dashboard:** KPI cards (GMV per currency, net revenue, new students, active students 7/30d, paid conversion, new courses), time-series charts, attention queues (applications pending, versions in review, open reports, stuck payments, failed payouts, refund requests). | Must |
| FR-02 | **Data tables** everywhere: server pagination/sort/filter, URL-synced state, column visibility, saved views (Should), row selection + bulk actions, CSV export (server-generated). | Must |
| FR-03 | **Global search / command palette:** find user by phone/name, course by title, order by number, payment by gateway reference. | Must |
| FR-04 | **Users:** list/detail (profile, devices, enrollments, orders, reports, activity); suspend/ban/reactivate with reason; grant enrollment; force sign-out; remove device. | Must |
| FR-05 | **Professor applications:** queue + detail (all materials, sample playback) + approve/reject/request info with note (PRD-02). | Must |
| FR-06 | **Professors:** list/detail (profile, courses, earnings, KYC, payout method); verify/reject KYC (secure viewer, access logged); revenue share override; suspend. | Must |
| FR-07 | **Content review:** queue of versions `in_review` (oldest first, SLA timer); review screen with diff vs live version, curriculum, lesson playback/preview, quiz preview; approve or reject with required notes per issue. | Must |
| FR-08 | **Courses:** list/detail; unpublish; feature/unfeature + featured ordering; takedown (immediate stop + notice to professor); transfer ownership (super admin). | Must |
| FR-09 | **Categories:** tree CRUD (fr/en names, icon, visibility), drag-and-drop ordering. | Must |
| FR-10 | **Moderation:** reports queue (course, review, thread, professor); actions: dismiss, hide content, warn, suspend; reviews and Q&A hide/restore. | Must |
| FR-11 | **Orders & payments:** list/detail with full timeline (order, payment attempts, gateway events, enrollments, ledger transaction); "recheck with gateway"; stuck-payments view. | Must |
| FR-12 | **Refunds:** queue with policy checks (window, progress) shown; approve/reject with reason; execution status. | Must |
| FR-13 | **Coupons & bundles:** CRUD, usage stats, deactivate. | Must |
| FR-14 | **Finance:** ledger accounts and transactions explorer; integrity check status; manual adjustments; payout batches (build, review, exclude, approve, execute, retry failed) — PRD-08. | Must |
| FR-15 | **Settings:** all platform settings (project-context table) grouped by area with descriptions, validation, "applies going forward" notice, change history. | Must |
| FR-16 | **Audit log:** every admin action and settings change with actor, target, before/after, reason, IP; filterable; immutable. | Must |
| FR-17 | **Staff:** invite staff by phone, assign roles/permissions, deactivate. | Must |
| FR-18 | Destructive/financial actions require a confirm dialog with typed reason. | Must |
| FR-19 | Back office in FR and EN; desktop-first, usable on tablet. | Must |

## 5. Business rules

- **BR-01** Back office uses only the admin API — no direct DB access and no business logic in the front end.
- **BR-02** A reviewer cannot approve their own course or application.
- **BR-03** KYC document views are logged per access.
- **BR-04** Financial actions above a threshold require a second approver (Should; threshold is a setting).

## 6. UX notes

- Layout: collapsible sidebar by domain (Overview, Users, Professors, Content, Moderation, Commerce, Finance, Settings), top bar with command palette, locale switch, user menu.
- Detail pages: status header with primary actions, tabbed body, activity timeline on the right/bottom.
- Consistent status badges and empty states (shared `packages/ui`).

## 7. Acceptance criteria

- [ ] Moderator cannot access finance pages or settings (UI hidden **and** API 403).
- [ ] Every action listed in FR-04 to FR-17 produces an audit log entry.
- [ ] Tables with 1M+ rows (e.g. payments) stay responsive (server pagination, indexed filters).
- [ ] Course review screen shows what changed versus the live version.
- [ ] Settings changes take effect for new operations only.

## 8. Analytics (internal)

Review SLA (time from submit to decision), application SLA, report resolution time, refund decision time — shown on the dashboard.

## 9. Open questions

- Review SLA targets — provisional: courses 48 h, applications 72 h (platform setting); confirm.
