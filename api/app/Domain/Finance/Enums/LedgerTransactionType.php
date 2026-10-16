<?php

declare(strict_types=1);

namespace App\Domain\Finance\Enums;

/** Kinds of ledger postings; one of each kind per reference. */
enum LedgerTransactionType: string
{
    case Sale = 'sale';
    case SaleReversal = 'sale_reversal';
    case UnappliedReceipt = 'unapplied_receipt';
    case UnappliedReceiptReversal = 'unapplied_receipt_reversal';
    case Release = 'release';
    case Refund = 'refund';
    case RefundPaid = 'refund_paid';
    /** An approved refund called off (payment reversed before its money went out). */
    case RefundReversal = 'refund_reversal';
    /** A payout that failed after the operator had already given the payer the money back. */
    case RefundSettled = 'refund_settled';
    /** An earlier payout attempt also went through: the payer was paid twice. */
    case RefundOverpaid = 'refund_overpaid';
    /** A completed payout the operator reversed: the money came back. */
    case RefundPaidReversal = 'refund_paid_reversal';
    case Payout = 'payout';
    case PayoutFailed = 'payout_failed';
    case Adjustment = 'adjustment';
}
