<?php

declare(strict_types=1);

namespace App\Domain\Platform\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Professor earnings and payouts (PRD-08).
 */
final class PayoutSettings extends Settings
{
    /** Days after a sale before earnings move from held to available (covers the refund window). */
    public int $earnings_hold_days;

    /** weekly | biweekly | monthly */
    public string $schedule;

    // Minimum available balance to be paid out, in minor units, per currency.
    /** @phpstan-var array<string, int> */
    public array $minimum_minor;

    public bool $platform_pays_disbursement_fees;

    /** Hours payouts are held after a professor changes their payout number. */
    public int $payout_method_change_hold_hours;

    public static function group(): string
    {
        return 'payouts';
    }
}
