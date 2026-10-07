<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

final readonly class GatewayWebhookEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        /** Provider event id — deduplicate on this before processing. Never empty. */
        public string $eventId,
        public TransactionKind $kind,
        /** Our reference. May arrive before our start call returns: store the event and retry if unknown. */
        public string $reference,
        public ?string $gatewayReference,
        public array $payload,
    ) {}
}
