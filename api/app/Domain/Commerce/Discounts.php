<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

use App\Domain\Shared\ValueObjects\Money;

/**
 * Splits an amount across order lines in whole collection steps (CDF: whole francs), so every line price
 * stays collectable and the parts add up exactly.
 */
final class Discounts
{
    /**
     * @param  list<int>  $weights  Non-negative, at least one positive.
     * @return list<Money>
     */
    public static function allocate(Money $amount, array $weights): array
    {
        $step = $amount->currency->collectionStepMinor();
        $units = Money::of(intdiv($amount->amountMinor, $step), $amount->currency);

        return array_map(fn (Money $part) => Money::of($part->amountMinor * $step, $amount->currency), $units->allocate($weights));
    }

    /** Rounds down to the currency's collection step. */
    public static function floorToStep(Money $amount): Money
    {
        $step = $amount->currency->collectionStepMinor();

        return Money::of(intdiv($amount->amountMinor, $step) * $step, $amount->currency);
    }
}
