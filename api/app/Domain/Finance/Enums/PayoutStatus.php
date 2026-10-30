<?php

declare(strict_types=1);

namespace App\Domain\Finance\Enums;

enum PayoutStatus: string
{
    case Pending = 'pending';
    case Excluded = 'excluded';
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';
}
