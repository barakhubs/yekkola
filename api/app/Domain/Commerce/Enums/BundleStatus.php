<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Enums;

enum BundleStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';
}
