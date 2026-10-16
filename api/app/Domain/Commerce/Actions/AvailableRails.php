<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Enums\MobileMoneyRail;
use App\Domain\Platform\Settings\CommerceSettings;
use App\Domain\Shared\Enums\Currency;
use App\Integrations\Payments\PaymentGateway;

/**
 * Which operators can take a payment right now (PRD-05 FR-19): enabled by the admin, through an enabled
 * gateway that supports the rail and currency. Paused rails are shown as unavailable, not hidden silently.
 */
final class AvailableRails
{
    public function __construct(
        private readonly CommerceSettings $settings,
        private readonly PaymentGateway $gateway,
    ) {}

    /**
     * @return array<string, bool> rail => available, for every rail
     */
    public function handle(Currency $currency): array
    {
        $gatewayEnabled = in_array($this->gateway->name(), $this->settings->enabled_gateways, true);
        $rails = [];

        foreach (MobileMoneyRail::cases() as $rail) {
            $rails[$rail->value] = $gatewayEnabled
                && in_array($rail->value, $this->settings->enabled_rails, true)
                && $this->gateway->supports($rail, $currency);
        }

        return $rails;
    }

    public function allows(MobileMoneyRail $rail, Currency $currency): bool
    {
        return $this->handle($currency)[$rail->value];
    }
}
