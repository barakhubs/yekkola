<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Enums;

/**
 * requested → approved → processing → completed | failed (staff retry → processing); requested → rejected;
 * a payment reversal cancels any refund whose money has not gone out.
 */
enum RefundStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    /** Called off because the payment was reversed (the payer got the money back from the operator). */
    case Cancelled = 'cancelled';
}
