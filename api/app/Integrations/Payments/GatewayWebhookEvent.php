<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

final readonly class GatewayWebhookEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        /** Provider event id — deduplicate on this before processing. */
        public string $eventId,
        public string $gatewayReference,
        /** "collection" or "disbursement". */
        public string $kind,
        public array $payload,
    ) {}
}
