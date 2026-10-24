<?php

declare(strict_types=1);

namespace App\Domain\Protection\Enums;

enum DocumentStampStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';
}
