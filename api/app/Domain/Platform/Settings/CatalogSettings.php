<?php

declare(strict_types=1);

namespace App\Domain\Platform\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Authoring and catalogue limits (PRD-03, PRD-04).
 */
final class CatalogSettings extends Settings
{
    /** Max free courses per professor; null = unlimited. */
    public ?int $free_course_limit;

    /** Max hours of free video/audio per professor; null = unlimited. */
    public ?int $free_media_hours_limit;

    public int $max_preview_lessons;

    public int $max_video_upload_mb;

    public int $max_audio_upload_mb;

    public int $max_document_upload_mb;

    public static function group(): string
    {
        return 'catalog';
    }
}
