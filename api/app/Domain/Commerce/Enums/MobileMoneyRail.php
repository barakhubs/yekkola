<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Enums;

/**
 * DRC mobile-money rails. MTN is not present in the DRC — don't add it.
 */
enum MobileMoneyRail: string
{
    case Orange = 'orange';
    case Airtel = 'airtel';
    case Mpesa = 'mpesa';
}
