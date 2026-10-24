<?php

declare(strict_types=1);

namespace App\Domain\Protection;

use App\Domain\Authoring\Enums\MediaKind;
use App\Integrations\Video\PlaybackTokens;

/** What a player needs to start one lesson (PRD-07 FR-02). */
final readonly class PlaybackGrant
{
    public function __construct(
        public string $lessonUid,
        public MediaKind $kind,
        public string $playbackId,
        public PlaybackTokens $tokens,
        /** Per-viewer overlay text; null for guests watching a preview. */
        public ?string $watermark,
        /** Web streams count toward the concurrent-stream limit and must send heartbeats. */
        public ?string $streamSessionId,
        public bool $isPreview,
    ) {}
}
