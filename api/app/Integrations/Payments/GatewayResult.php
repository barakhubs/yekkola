<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

final readonly class GatewayResult
{
    /**
     * @param  array<string, mixed>  $raw  Provider payload, stored for support/audit (never shown to users).
     */
    public function __construct(
        public string $gatewayReference,
        public GatewayStatus $status,
        /** Stable failure code, e.g. "insufficient_funds", "rejected_by_payer", "timeout". */
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
        public array $raw = [],
    ) {}
}
