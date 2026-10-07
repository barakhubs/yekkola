<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

use App\Domain\Commerce\Enums\MobileMoneyRail;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\PhoneNumber;

final readonly class DisbursementRequest
{
    public function __construct(
        /** Our payout/refund id — the idempotency reference sent to the gateway. */
        public string $reference,
        public Money $amount,
        public MobileMoneyRail $rail,
        public PhoneNumber $recipient,
        public string $recipientName,
        public string $description,
    ) {}
}
