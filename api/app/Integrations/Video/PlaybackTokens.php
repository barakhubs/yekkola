<?php

declare(strict_types=1);

namespace App\Integrations\Video;

use Carbon\CarbonImmutable;

final readonly class PlaybackTokens
{
    public function __construct(
        public string $playbackToken,
        /** Null for audio (signed playback only). */
        public ?string $drmToken,
        public CarbonImmutable $expiresAt,
    ) {}
}
