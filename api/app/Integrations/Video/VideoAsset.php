<?php

declare(strict_types=1);

namespace App\Integrations\Video;

final readonly class VideoAsset
{
    public function __construct(
        public string $id,
        public VideoAssetStatus $status,
        public ?string $playbackId = null,
        public ?float $durationSeconds = null,
        public ?string $passthrough = null,
        public ?string $errorMessage = null,
    ) {}
}
