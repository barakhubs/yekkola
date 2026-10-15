<?php

declare(strict_types=1);

namespace App\Domain\Authoring\Enums;

/**
 * Lesson kinds (PRD-03).
 */
enum LessonType: string
{
    case Video = 'video';
    case Audio = 'audio';
    case Document = 'document';
    case Quiz = 'quiz';

    /** Lessons whose content is hosted on the video provider. */
    public function usesMedia(): bool
    {
        return $this === self::Video || $this === self::Audio;
    }

    public function mediaKind(): ?MediaKind
    {
        return match ($this) {
            self::Video => MediaKind::Video,
            self::Audio => MediaKind::Audio,
            default => null,
        };
    }
}
