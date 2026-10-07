# PRD-05 — Checkout & Payments

| | |
|---|---|
| Status | Draft |
| Phase | 1 (API with fake gateway), 2 (web), 3 (mobile); real aggregator integrated later — required before launch |
| Related | PRD-06, PRD-08, PRD-09, architecture §1.3-C, §3.4 |

## 1. Summary

Students enroll in free courses with one tap and buy paid courses with **mobile money** (Orange Money, Airtel Money, M-Pesa). Payments are asynchronous (the student approves a USSD/push prompt on their phone), so checkout is a state machine with clear waiting, success, failure, and retry states. Coupons, bundles, parent-pays, refunds, and receipts are included.

## 2. Goals & non-goals

**Goals**
- Paid checkout completes in under 2 minutes for a typical user.
- No student is charged without getting access; no access without a confirmed payment.
- Every money movement is traceable from order to ledger.

**Non-goals**
- Card payments, bank transfer — not at launch.
- Subscriptions, wallets/credits, currency conversion.

## 3. User stories

1. As a student, I tap "S'inscrire gratuitement" on a free course and start learning immediately.
2. As a student, I buy a course: choose my operator, confirm my number, approve the prompt on my phone, and get access.
3. As a student, I apply a coupon code and see the discounted price before paying.
4. As a student, I buy a bundle of courses at a bundle price.
5. As a parent, I pay for my child's course using my phone number while the course goes to my child's account.
6. As a student, if payment fails or times out, I can retry or switch operator without creating duplicate orders.
7. As a student, I see my order history and download receipts.
8. As a student, I request a refund within the refund window.

## 4. Functional requirements

| ID | Requirement | Priority |
|---|---|---|
| FR-01 | Free enroll: one tap, creates `Enrollment(source=free)`; idempotent (enrolling twice = no-op). Requires sign-in. | Must |
| FR-02 | Quote: server-side price for courses/bundle + coupon → subtotal, discount, total, currency. Client totals are never trusted. | Must |
| FR-03 | Create order with `Idempotency-Key`; items snapshot list price, discount, final price, revenue share, discount funder. | Must |
| FR-04 | Already-enrolled courses are excluded from quotes/orders (error if the only item). | Must |
| FR-05 | Start payment: choose rail (only enabled rails), payer number (default: my phone), → `PaymentGateway::collect`. | Must |
| FR-06 | Waiting screen: "Approve the payment on your phone" with countdown (order payment timeout setting, default 15 min); status polled with backoff. | Must |
| FR-07 | Webhook + re-query: payment confirmed only after verifying with the gateway API. | Must |
| FR-08 | On success: order `paid`, enrollments created, ledger posted (PRD-08), receipt notification (PRD-10). All in one transactional handler; safe to run twice. | Must |
| FR-09 | On failure/expiry: clear reason (insufficient balance, rejected, timeout), retry with same or another rail on the same order until it expires. | Must |
| FR-10 | Reconciliation job: every 5 min re-query payments `pending` beyond 2 min; expire orders past timeout; late successes on expired orders still grant access (and are flagged for review). | Must |
| FR-11 | Coupons: percent or fixed; scope platform/professor/course; validity window; max redemptions; per-user limit; funded by platform/professor/shared. Redemption counted only on payment success. | Must |
| FR-12 | Professor coupons (PRD-03 studio): only for their own courses, professor-funded. | Should |
| FR-13 | Bundles: fixed price for a set of courses; owned courses in a bundle → **Open** (see §10). Revenue split across professors pro-rata to list prices. | Should |
| FR-14 | Parent payer (setting-controlled): order has `buyer`/`payer_msisdn` distinct from `beneficiary`; parent can pay without an account by entering the student's phone + their own payer number; receipt sent to both. | Should |
| FR-15 | Receipts: order number, items, amounts, currency, rail, payment reference, date; PDF downloadable; FR/EN. | Must |
| FR-16 | Refund request within refund window and below max progress (settings) → admin approval (PRD-09) → `PaymentGateway::refund` or disbursement → enrollment revoked → ledger reversal. | Must |
| FR-17 | Order history page with status and receipt links. | Must |
| FR-18 | `FakeGateway` driver supports scripted outcomes (success, fail, pending forever, late success, reversal) for tests and staging. | Must |
| FR-19 | Admin can pause a rail or gateway (setting); checkout hides paused rails with a message. | Must |

## 5. Business rules

- **BR-01** Access is granted only by `Enrollment`; enrollments from purchases are created only by the payment-success handler.
- **BR-02** One currency per order; the course's currency is the order currency. Mixed-currency carts are not allowed.
- **BR-03** Price snapshot at order creation is honored for that order even if the course price changes before payment.
- **BR-04** Reversal after success (chargeback-like) → enrollment revoked, ledger reversal, admin alert.
- **BR-05** Coupon discount never makes a paid total < gateway minimum; if 100% discount → treated as a free enrollment with `source=coupon` (no payment).
- **BR-06** Refund window, max progress for refunds, payment timeout: platform settings.

## 6. UX notes

- Operator picker with logos; phone number pre-filled and editable; amount shown in the course currency.
- Waiting screen is the critical moment: big, calm instructions in FR/EN with operator-specific hints (e.g. "Composez *xxx#" if prompt didn't arrive) — exact hints per rail come with the aggregator integration.
- Never show a success state before the server confirms.

## 7. Edge cases

- Student closes the app during waiting → on return, order status is fetched; success shows access.
- Duplicate webhooks / webhook before client poll → idempotent handling.
- Gateway down → rail shown unavailable; order stays retryable until expiry.
- Payer number differs from account number → allowed (parent flow), recorded.
- Course unpublished between quote and payment → order still completes (snapshot); course remains accessible to buyer.

## 8. Acceptance criteria

- [ ] Free enroll grants immediate access; repeating it changes nothing.
- [ ] With FakeGateway "success", a paid order results in exactly one enrollment and one balanced ledger transaction, even if the webhook is delivered 3 times.
- [ ] "Pending forever" expires at timeout; a late success afterwards still grants access and is flagged.
- [ ] Coupon limits are enforced under concurrent checkouts.
- [ ] A refunded order revokes access and posts reversal entries.

## 9. Analytics events

`enroll_free_clicked`, `checkout_started`, `coupon_applied {valid}`, `order_created`, `payment_initiated {rail}`, `payment_succeeded {rail, seconds_to_confirm}`, `payment_failed {rail, reason}`, `payment_expired`, `refund_requested`, `refund_completed`.

## 10. Dependencies & open questions

- Aggregator selection (collection + disbursement on all three rails) — deferred, **must** be done before launch.
- Bundle containing an already-owned course: discount or block? — **Open**.
- Tax (VAT) on sales and receipt requirements — **Open**, needs a DRC accountant.
