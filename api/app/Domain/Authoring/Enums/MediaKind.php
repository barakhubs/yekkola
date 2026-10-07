<?php

declare(strict_types=1);

namespace App\Domain\Authoring\Enums;

enum MediaKind: string
{
    case Video = 'video';
    case Audio = 'audio';

    /** Mux supports DRM on video only; audio uses signed playback (docs/research/mux.md §2). */
    public function supportsDrm(): bool
    {
        return $this === self::Video;
    }
}
