<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Enums;

/**
 * requested → approved → processing → completed | failed (staff retry → processing); requested → rejected.
 */
enum RefundStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
