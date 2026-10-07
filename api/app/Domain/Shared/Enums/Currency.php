<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * Currencies the platform can price and pay in. Which ones are active is a platform setting.
 */
enum Currency: string
{
    case USD = 'USD';
    case CDF = 'CDF';

    /** Number of minor-unit digits (ISO 4217). */
    public function minorUnits(): int
    {
        return match ($this) {
            self::USD, self::CDF => 2,
        };
    }

    /**
     * Smallest amount mobile money can actually move, in minor units. CDF is collected in whole francs,
     * so every price, quote, and order total must be a multiple of this (round when computing them).
     */
    public function collectionStepMinor(): int
    {
        return match ($this) {
            self::USD => 1,
            self::CDF => 100,
        };
    }
}
