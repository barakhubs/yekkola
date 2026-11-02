<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Enums;

enum ReportStatus: string
{
    case Open = 'open';
    /** Staff acted on the item (hid it, warned or suspended its author). */
    case Actioned = 'actioned';
    case Dismissed = 'dismissed';
}
