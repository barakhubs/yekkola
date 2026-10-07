<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

use App\Domain\Commerce\Enums\MobileMoneyRail;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\PhoneNumber;

final readonly class CollectionRequest
{
    public function __construct(
        /** Our payment id — the idempotency reference sent to the gateway. */
        public string $reference,
        public Money $amount,
        public MobileMoneyRail $rail,
        public PhoneNumber $payer,
        public string $description,
    ) {}
}
