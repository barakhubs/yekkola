<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Authoring\Enums\LessonType;
use App\Domain\Authoring\Models\Lesson;

/**
 * Estimated download size per lesson at the lowest offline tier (PRD-04 FR-05, PRD-03 FR-20), so students
 * see the data cost before committing. Video/audio: duration × configured bitrate; documents: file size.
 */
final class DownloadEstimate
{
    public function bytes(Lesson $lesson): int
    {
        return match ($lesson->type) {
            LessonType::Video, LessonType::Audio => (int) round(
                $lesson->duration_seconds * (int) config('yekkola.catalogue.download_kbps.'.$lesson->type->value) * 1_000 / 8
            ),
            LessonType::Document => (int) ($lesson->getFirstMedia(Lesson::DOCUMENT)->size ?? 0),
            LessonType::Quiz => 0,
        };
    }
}
