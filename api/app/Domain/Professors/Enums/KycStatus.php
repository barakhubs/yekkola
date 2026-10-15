<?php

declare(strict_types=1);

namespace App\Domain\Professors\Enums;

enum KycStatus: string
{
    case None = 'none';
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
}
