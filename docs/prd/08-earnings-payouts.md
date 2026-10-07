# PRD-08 — Earnings & Payouts

| | |
|---|---|
| Status | Draft |
| Phase | 1 (ledger + fake disbursement), 2 (studio + back office); real disbursement with the aggregator |
| Related | PRD-02 (KYC, payout method), PRD-05, PRD-09, architecture §1.3-G, §3.7 |

## 1. Summary

Every sale is split between the professor and the platform and recorded in a **double-entry, append-only ledger**. Professors see their earnings (held → available → paid out) and receive **payouts to their mobile-money number** in scheduled batches approved by finance admins. Transparency here is what makes professors trust the platform.

## 2. Goals & non-goals

**Goals**
- Professors can reconcile every franc/dollar: each sale, fee, refund, and payout is visible.
- Payouts are predictable (schedule + minimum) and safe (approval, KYC, holds).
- Finance can prove the ledger balances at any time.

**Non-goals**
- Instant on-demand payouts — batches only at launch.
- Multi-currency conversion — balances are kept per currency.

## 3. User stories

1. As a professor, I see my balances: held (in refund window), available, paid out — per currency.
2. As a professor, I see a statement of every sale, refund, and payout with dates and references.
3. As a professor, I see upcoming payout date and whether I meet the minimum.
4. As a professor, I'm notified when a payout is sent and when it fails (with reason).
5. As a finance admin, I review a generated payout batch, exclude items if needed, approve, and execute it.
6. As a finance admin, I post a manual adjustment with a reason, audited.

## 4. Functional requirements

| ID | Requirement | Priority |
|---|---|---|
| FR-01 | Ledger accounts per type/owner/currency (see architecture §3.7). | Must |
| FR-02 | Sale posting (on payment success): debit gateway clearing (total); credit professor held (share); credit platform revenue (remainder); discount entries per funder. | Must |
| FR-03 | Release job: after earnings hold period (setting), move professor held → available. | Must |
| FR-04 | Refund posting: reverse proportional professor and platform amounts (from held if still held, else from available — may go negative). | Must |
| FR-05 | Negative available balance carries forward and is netted against future earnings. | Must |
| FR-06 | Payout batch builder (schedule setting): professors with available ≥ minimum, verified KYC, payout method set, not suspended, not under payout-method-change hold. | Must |
| FR-07 | Batch review UI (PRD-09): totals per currency/rail, per-professor lines, exclude line with reason, approve (finance permission, 2nd approver above threshold: Should). | Must |
| FR-08 | Execution: `PaymentGateway::disburse` per line on the `payments` queue; status via webhook/poll; ledger posting on success (available → paid out); failure returns to available with reason. | Must |
| FR-09 | Professor earnings dashboard: balances, charts (earnings over time), statement table (filter by date/course/type), CSV export. | Must |
| FR-10 | Monthly statement PDF per professor. | Should |
| FR-11 | Manual adjustment (finance only): balanced transaction with memo + reason; visible to the professor with label. | Must |
| FR-12 | Ledger integrity check (scheduled): every transaction balanced; account balances equal sum of entries; alert on mismatch. | Must |
| FR-13 | Fake disbursement driver (success/failure/pending) until real aggregator is integrated. | Must |

## 5. Business rules

- **BR-01** Revenue share is taken from the order item snapshot, never recomputed.
- **BR-02** Ledger rows are never updated or deleted; corrections are reversing/adjusting transactions.
- **BR-03** Payout fees: paid by the platform *(provisional setting)*.
- **BR-04** Withholding tax on professor earnings — **Open** (accountant).
- **BR-05** Payouts are in the same currency the earnings were made in.

## 6. UX notes

- Studio earnings page leads with three numbers: *En attente* (held), *Disponible* (available), *Versé* (paid out), plus "Next payout: date".
- Each statement row links to its order (anonymized student) or payout.

## 7. Edge cases

- Refund after payout → negative available; netted next time (FR-05).
- Payout to an invalid number → fails; professor notified to update payout method.
- Professor suspended mid-batch → line excluded automatically before execution.
- Gateway reports success late after we marked failed → reconcile: re-post as paid, alert finance.

## 8. Acceptance criteria

- [ ] Every ledger transaction balances (enforced by tests and the integrity job).
- [ ] Professor balances equal the sum of their ledger entries.
- [ ] A professor without verified KYC never appears in a batch.
- [ ] A failed payout returns funds to available and notifies the professor.
- [ ] All batch approvals and adjustments appear in the audit log.

## 9. Analytics events

`earnings_viewed`, `statement_exported`, `payout_batch_built {count, totals}`, `payout_batch_approved`, `payout_sent`, `payout_failed {reason}`.

## 10. Open questions

- Default revenue split, payout schedule, minimum payout — business decisions → settings.
- Disbursement fee responsibility — provisional: platform pays; confirm.
- Tax/withholding — **Open**.
