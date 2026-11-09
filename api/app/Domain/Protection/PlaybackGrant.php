<?php

declare(strict_types=1);

namespace App\Domain\Protection;

use App\Domain\Authoring\Enums\MediaKind;
use Carbon\CarbonImmutable;

/** What a player needs to start one lesson (PRD-07 FR-02). */
final readonly class PlaybackGrant
{
    public function __construct(
        public string $lessonUid,
        public MediaKind $kind,
        /** Master playlist on our API (it carries the stream session for web players). */
        public string $playlistUrl,
        /** Short-lived poster frame URL; null for audio. */
        public ?string $posterUrl,
        /** Ask for playback again after this (the player re-fetches on an expired segment or key). */
        public CarbonImmutable $expiresAt,
        /** Per-viewer overlay text; null for guests watching a preview. */
        public ?string $watermark,
        /** Web streams count toward the concurrent-stream limit and must send heartbeats. */
        public ?string $streamSessionId,
        public bool $isPreview,
    ) {}
}
