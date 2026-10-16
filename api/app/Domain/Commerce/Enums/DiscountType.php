<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Enums;

enum DiscountType: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';
}
