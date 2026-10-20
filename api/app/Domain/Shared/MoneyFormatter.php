<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Support\Number;

/**
 * The one way money is written for people (receipts, SMS, emails): locale-aware, CDF without decimals
 * (it is collected in whole francs).
 */
final class MoneyFormatter
{
    public static function format(Money $money, string $locale): string
    {
        $decimals = $money->currency->collectionStepMinor() === 1 ? $money->currency->minorUnits() : 0;
        $amount = $money->amountMinor / (10 ** $money->currency->minorUnits());

        return (string) Number::currency($amount, in: $money->currency->value, locale: $locale, precision: $decimals);
    }
}
