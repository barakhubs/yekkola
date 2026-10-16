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
    case Payout = 'payout';
    case PayoutFailed = 'payout_failed';
    case Adjustment = 'adjustment';
}
