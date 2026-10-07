# PRD-10 — Notifications

| | |
|---|---|
| Status | Draft |
| Phase | 1 (API, in-app + email + push), 2 (web), 3 (mobile push); real SMS later |
| Related | All PRDs; architecture §3.8 |

## 1. Summary

Yekkola tells users what matters — payment results, course approvals, answers to questions, payouts — through **in-app**, **push** (FCM), **email**, and **SMS**. SMS costs money and is reserved for critical messages. Users control non-critical notifications.

## 2. Goals & non-goals

**Goals**
- Critical events always reach the user (payment, OTP, payout).
- Users aren't spammed; preferences are respected.
- All messages are translated (FR/EN by user locale).

**Non-goals**
- Marketing campaigns / newsletters tooling — not at launch (Could later).
- WhatsApp Business API messaging — **Open** (§10).

## 3. Notification catalogue

| Event | Recipient | In-app | Push | Email | SMS | User can disable |
|---|---|---|---|---|---|---|
| OTP code | User | — | — | — | ✓ | No |
| Payment succeeded (receipt) | Buyer (+ parent payer) | ✓ | ✓ | ✓ (if email) | ✓ (payer, if no app) | No |
| Payment failed / expired | Buyer | ✓ | ✓ | — | — | No |
| Refund decided | Student | ✓ | ✓ | ✓ | — | No |
| New version of an enrolled course | Student | ✓ | ✓ | — | — | Yes |
| Answer to my question | Student | ✓ | ✓ | — | — | Yes |
| Certificate issued | Student | ✓ | ✓ | ✓ | — | Yes |
| Download license expiring (offline) | Student | ✓ (local) | ✓ (local) | — | — | Yes |
| Application decided / info requested | Applicant | ✓ | ✓ | ✓ | ✓ | No |
| Course version approved / rejected | Professor | ✓ | ✓ | ✓ | — | No |
| New question on my lesson | Professor | ✓ | ✓ | digest | — | Yes |
| New review | Professor | ✓ | ✓ | digest | — | Yes |
| New sale | Professor | ✓ | ✓ | digest | — | Yes |
| Payout sent / failed | Professor | ✓ | ✓ | ✓ | ✓ | No |
| KYC decided | Professor | ✓ | ✓ | ✓ | — | No |
| Account suspended / banned | User | ✓ | — | ✓ | ✓ | No |
| Staff alerts (stuck payments, integrity failure, failed batch) | Admins | ✓ | — | ✓ | — | Per role |

## 4. Functional requirements

| ID | Requirement | Priority |
|---|---|---|
| FR-01 | Laravel notifications with channels: database (in-app), mail, FCM, SMS (`SmsSender`). All sent from queues. | Must |
| FR-02 | Templates per event in FR/EN using the recipient's locale; SMS templates ≤ 160 GSM characters (no accents issues — test encoding). | Must |
| FR-03 | In-app notification centre (web + mobile): list, unread count, mark read, deep links to the relevant screen. | Must |
| FR-04 | Push: FCM tokens per device; deep links; quiet hours 21:00–07:00 local for non-critical pushes (Should). | Must |
| FR-05 | Preferences per event type × channel for disable-able events. | Must |
| FR-06 | Professor digest email (daily) for sales, reviews, questions instead of one email each. | Should |
| FR-07 | Delivery tracking: SMS delivery reports via webhook; failures logged and visible in the user's admin detail. | Should |
| FR-08 | Local notifications on mobile: download complete, license expiring. | Must (phase 3) |
| FR-09 | Admin alert routing by role (finance alerts → finance staff). | Must |
| FR-10 | Rate limiting per user for non-critical notifications (e.g. max 5 pushes/day). | Should |

## 5. Business rules

- **BR-01** SMS is used only where the table marks it — SMS spend is a cost line.
- **BR-02** Critical notifications ignore preferences (but still respect channel availability).
- **BR-03** Notifications never contain full phone numbers of other users or payment secrets.

## 6. Acceptance criteria

- [ ] Each event in the catalogue triggers the listed channels in the user's language.
- [ ] Disabled preferences suppress only disable-able events.
- [ ] Notifications are sent from the queue; a failing channel doesn't block others.
- [ ] Deep links open the correct screen on web and mobile.

## 7. Analytics events

`notification_sent {type, channel}`, `notification_opened {type, channel}`, `notification_preference_changed`, `sms_delivery_failed`.

## 8. Open questions

- WhatsApp as a channel (receipts, answers) via WhatsApp Business API — provisional: post-launch backlog.
- Transactional email provider (e.g. Postmark/Resend/SES) — **Open**, small decision.
