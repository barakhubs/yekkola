<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Enums;

/** Order lifecycle (PRD-05). Only `paid` (and partial refunds) grant access. */
enum OrderStatus: string
{
    case Pending = 'pending';
    case AwaitingPayment = 'awaiting_payment';
    case Paid = 'paid';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Reversed = 'reversed';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    /** A payment may still be started on the order (before it expires). */
    public function acceptsPayment(): bool
    {
        return $this === self::Pending || $this === self::AwaitingPayment;
    }
}
