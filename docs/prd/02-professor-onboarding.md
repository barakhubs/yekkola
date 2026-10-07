# PRD-02 — Professor Onboarding & Vetting

| | |
|---|---|
| Status | Draft |
| Phase | 1 (API), 2 (web studio + back office) |
| Related | PRD-01, PRD-03, PRD-08, PRD-09, architecture §3.2 |

## 1. Summary

Any user can apply to teach. Applications are **reviewed manually** by Yekkola staff — curation is the brand promise ("Congo's best teachers"). Approved professors get a public profile, access to the studio, and must complete identity verification (KYC) and payout details before they can be paid.

## 2. Goals & non-goals

**Goals**
- A clear, mobile-friendly application that takes < 10 minutes.
- A review queue that lets staff decide quickly and consistently.
- Professors from any province — no location requirement.

**Non-goals**
- Automated approval or AI scoring.
- Organisation/school accounts (institutional licensing is out of scope).

## 3. User stories

1. As a teacher, I apply with my subjects, experience, credentials, and a short sample (video link or upload).
2. As an applicant, I see my application status and any request for more information.
3. As a reviewer, I see a queue of applications, review each, and approve, reject (with reason), or ask for more info.
4. As an approved professor, I set up my public profile (photo, headline, bio, subjects, province).
5. As a professor, I upload an ID document for verification and set my mobile-money payout number.
6. As a student, I see a "Vérifié" badge on verified professors.

## 4. Functional requirements

| ID | Requirement | Priority |
|---|---|---|
| FR-01 | Application form: full name, subjects (from categories), level taught, years of experience, current/previous institutions, credentials (text + optional uploads), sample lesson (upload ≤ 5 min or link), motivation, province (optional), agreement to professor terms. | Must |
| FR-02 | Draft autosave; submit locks the application. One open application per user. | Must |
| FR-03 | Status visible to applicant: submitted, under review, needs info, approved, rejected (with reason). | Must |
| FR-04 | Admin queue (PRD-09): filter by status, subject, date; detail view with all materials; actions approve / reject / request info, each with a note. | Must |
| FR-05 | On approve: create `ProfessorProfile`, grant professor role, notify, unlock studio. | Must |
| FR-06 | Rejected applicants may reapply after a cooldown (setting, default 30 days). | Should |
| FR-07 | Profile editor: display name, slug (auto, editable once), photo, headline, bio (rich text, limited), subjects, social/website links, province. | Must |
| FR-08 | KYC: upload ID (national ID / voter card / passport) front + back + selfie; stored on private disk; staff verify or reject. | Must |
| FR-09 | Payout method: rail + phone number + account name; changing it requires OTP and triggers a 48-hour payout hold. | Must |
| FR-10 | Professor terms acceptance recorded with version and timestamp; re-acceptance required when terms change. | Must |
| FR-11 | Admin can suspend a professor: courses unpublished from catalogue (existing students keep access unless taken down), payouts held. | Must |
| FR-12 | Public professor page (PRD-04) shows only approved, active professors. | Must |

## 5. Business rules

- **BR-01** Professors can create and submit courses before KYC is verified, but **cannot receive payouts** until KYC is verified and a payout method exists.
- **BR-02** Revenue share override per professor is admin-only (PRD-08, PRD-09).
- **BR-03** A suspended professor's earnings stay in the ledger; payouts resume only after reinstatement.
- **BR-04** KYC documents are never shown outside the admin KYC screen; access is logged.

## 6. UX notes

- "Teach on Yekkola" landing page (`/teach`) explaining benefits, process, and requirements, with the apply CTA.
- Application is multi-step with progress indicator; works on a phone.
- Studio shows an onboarding checklist: profile ✓, KYC ✓, payout method ✓, first course ✓.

## 7. Edge cases

- Applicant edits nothing after "needs info" for 30 days → auto-close as expired.
- Professor changes payout number and immediately requests payout → hold (FR-09).
- KYC rejected → reason shown, re-upload allowed.

## 8. Acceptance criteria

- [ ] A user can submit an application and see "submitted".
- [ ] Reviewer approves → user gains professor role and can open the studio.
- [ ] Payouts for a professor without verified KYC are excluded from payout batches.
- [ ] KYC files are not reachable via any public URL.
- [ ] Every application decision is in the audit log with reviewer and note.

## 9. Analytics events

`teach_page_viewed`, `application_started`, `application_submitted`, `application_decided {decision}`, `kyc_submitted`, `kyc_decided {decision}`, `payout_method_set`.

## 10. Open questions

- Required credentials per category (e.g. proof of teaching for Exetat prep)? — **Open**, decided by the content team.
- Professor agreement content (ownership, licence, exclusivity) — legal, **Open**.
