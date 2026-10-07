<?php

declare(strict_types=1);

namespace App\Integrations\Video;

final readonly class VideoWebhookEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        /** Provider event id — deduplicate on this before processing. */
        public string $eventId,
        public VideoEventType $type,
        public ?string $assetId,
        public ?string $uploadId,
        public ?string $passthrough,
        public array $payload,
    ) {}
}
