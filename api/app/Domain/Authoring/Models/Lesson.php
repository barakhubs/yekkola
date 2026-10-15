<?php

declare(strict_types=1);

namespace App\Domain\Authoring\Models;

use App\Domain\Authoring\Enums\LessonType;
use App\Domain\Authoring\Enums\MediaAssetStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * One lesson in a version. `uid` is stable across versions (progress, quiz history, Q&A follow it).
 * Video/audio content is a MediaAsset on the provider; a document is a PDF in the `document` media
 * collection on the private disk (students get stamped copies); a quiz has a Quiz.
 *
 * @property string $id
 * @property string $course_version_id
 * @property string $section_id
 * @property string $uid
 * @property LessonType $type
 * @property string $title
 * @property string|null $description
 * @property int $position
 * @property bool $is_preview
 * @property int|null $duration_seconds
 * @property string|null $media_asset_id
 */
final class Lesson extends Model implements HasMedia
{
    use HasUlids;
    use InteractsWithMedia;

    public const DOCUMENT = 'document';

    protected $guarded = [];

    protected $attributes = [
        'is_preview' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LessonType::class,
            'position' => 'integer',
            'is_preview' => 'boolean',
            'duration_seconds' => 'integer',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::DOCUMENT)
            ->singleFile()
            ->useDisk((string) config('yekkola.private_disk'))
            ->acceptsMimeTypes(['application/pdf']);
    }

    /**
     * @return BelongsTo<CourseVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(CourseVersion::class, 'course_version_id');
    }

    /**
     * @return BelongsTo<Section, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /**
     * @return BelongsTo<MediaAsset, $this>
     */
    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }

    /**
     * @return HasOne<Quiz, $this>
     */
    public function quiz(): HasOne
    {
        return $this->hasOne(Quiz::class);
    }

    /** Content is attached and usable (submit checklist, FR-10). */
    public function isContentReady(): bool
    {
        return match ($this->type) {
            LessonType::Video, LessonType::Audio => $this->mediaAsset?->status === MediaAssetStatus::Ready,
            LessonType::Document => $this->getFirstMedia(self::DOCUMENT) !== null,
            LessonType::Quiz => ($this->quiz?->questions()->count() ?? 0) > 0,
        };
    }
}
