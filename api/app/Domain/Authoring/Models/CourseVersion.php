<?php

declare(strict_types=1);

namespace App\Domain\Authoring\Models;

use App\Domain\Authoring\Enums\VersionState;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A snapshot of a course's content (PRD-03 FR-11–13). Only drafts and rejected versions are editable;
 * approved and superseded versions are never changed. The cover image is in the `cover` media collection.
 *
 * @property string $id
 * @property string $course_id
 * @property int $number
 * @property VersionState $state
 * @property string|null $title
 * @property string|null $subtitle
 * @property string|null $description
 * @property list<string> $outcomes
 * @property list<string> $requirements
 * @property string|null $changelog
 * @property \Illuminate\Support\Carbon|null $submitted_at
 * @property string|null $reviewed_by
 * @property \Illuminate\Support\Carbon|null $reviewed_at
 * @property string|null $review_notes
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class CourseVersion extends Model implements HasMedia
{
    use HasUlids;
    use InteractsWithMedia;

    public const COVER = 'cover';

    protected $guarded = [];

    protected $attributes = [
        'outcomes' => '[]',
        'requirements' => '[]',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => VersionState::class,
            'number' => 'integer',
            'outcomes' => 'array',
            'requirements' => 'array',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::COVER)
            ->singleFile()
            ->useDisk((string) config('yekkola.public_disk'))
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return HasMany<Section, $this>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(Section::class)->orderBy('position');
    }

    /**
     * @return HasMany<Lesson, $this>
     */
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    public function isEditable(): bool
    {
        return $this->state->isEditable();
    }

    public function coverUrl(): ?string
    {
        $url = $this->getFirstMediaUrl(self::COVER);

        return $url !== '' ? $url : null;
    }
}
