<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

use App\Domain\Shared\ValueObjects\Money;
use Carbon\CarbonImmutable;

final readonly class GatewayResult
{
    /**
     * @param  array<string, mixed>  $raw  Provider payload, stored for support/audit (never shown to users).
     */
    public function __construct(
        /** Our reference, echoed back. */
        public string $reference,
        public string $gatewayReference,
        public GatewayStatus $status,
        /** Amount the gateway confirms — compare with what we requested before acting. Null while pending. */
        public ?Money $amount = null,
        /** Fee the gateway deducts or charges (posted to the ledger's gateway-fees account). */
        public ?Money $fee = null,
        /** Operator transaction id (shown on receipts). */
        public ?string $operatorReference = null,
        public ?CarbonImmutable $completedAt = null,
        /** Stable failure code: insufficient_funds, rejected_by_payer, invalid_recipient, timeout, … */
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
        public array $raw = [],
    ) {}
}
