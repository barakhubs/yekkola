<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

enum DataExportStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';
}
