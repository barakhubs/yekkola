<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Enums;

/** One payment attempt on an order. Any status can still move (late success, reversal). */
enum PaymentStatus: string
{
    case Initiated = 'initiated';
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Expired = 'expired';
    case Reversed = 'reversed';

    /** The payer may still be approving it: no new attempt on the order meanwhile. */
    public function inFlight(): bool
    {
        return $this === self::Initiated || $this === self::Pending;
    }
}
