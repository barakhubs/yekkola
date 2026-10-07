<?php

declare(strict_types=1);

namespace App\Integrations\Video;

use Carbon\CarbonImmutable;

/**
 * Tokens a player needs for one protected asset. Mux signs each use separately
 * (playback "v", DRM "d", thumbnail "t", storyboard "s"); Mux Player takes them as `tokens.*`.
 */
final readonly class PlaybackTokens
{
    public function __construct(
        public string $playbackToken,
        /** Null for audio (signed playback only). */
        public ?string $drmToken,
        public CarbonImmutable $expiresAt,
        /** Poster images. Null for audio. */
        public ?string $thumbnailToken = null,
        /** Timeline hover previews. Null for audio. */
        public ?string $storyboardToken = null,
    ) {}
}
