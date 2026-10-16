<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Enums;

/** One refund payout attempt at the gateway. A failed attempt can still succeed late; a success can be reversed. */
enum AttemptStatus: string
{
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Reversed = 'reversed';
}
