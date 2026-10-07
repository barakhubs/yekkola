<?php

declare(strict_types=1);

namespace App\Domain\Platform\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Pricing, checkout, and refunds. Admin-editable; changes apply going forward only.
 * Defaults marked provisional in project-context.md → Platform settings.
 */
final class CommerceSettings extends Settings
{
    // Array types use @phpstan-var: spatie/laravel-settings parses @var for casts and can't handle generics.

    // ISO 4217 codes, subset of App\Domain\Shared\Enums\Currency.
    /** @phpstan-var array<int, string> */
    public array $enabled_currencies;

    public string $default_currency;

    /** Professor's share of a sale in basis points (7000 = 70%). Overridable per professor and course. */
    public int $default_revenue_share_bps;

    // Mobile-money rails accepting payments (App\Domain\Commerce\Enums\MobileMoneyRail values).
    /** @phpstan-var array<int, string> */
    public array $enabled_rails;

    // Course price limits in minor units, per currency: ['USD' => ['min' => 100, 'max' => 50000], ...].
    /** @phpstan-var array<string, array<string, int>> */
    public array $price_limits_minor;

    public int $order_payment_timeout_minutes;

    public int $refund_window_days;

    public int $refund_max_progress_pct;

    public bool $separate_payer_enabled;

    public static function group(): string
    {
        return 'commerce';
    }
}
