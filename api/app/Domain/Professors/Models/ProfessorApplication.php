<?php

declare(strict_types=1);

namespace App\Domain\Professors\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Province;
use App\Domain\Professors\Enums\ApplicationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A teacher's application to become a professor (PRD-02 FR-01–06). Credential files are in the
 * `credentials` media collection on the private disk.
 *
 * @property string $id
 * @property string $user_id
 * @property ApplicationStatus $status
 * @property string|null $full_name
 * @property list<string> $subjects
 * @property list<string> $levels
 * @property int|null $years_experience
 * @property string|null $institutions
 * @property string|null $credentials
 * @property string|null $sample_url
 * @property string|null $motivation
 * @property string|null $province_id
 * @property string|null $terms_version
 * @property \Illuminate\Support\Carbon|null $submitted_at
 * @property string|null $reviewer_id
 * @property \Illuminate\Support\Carbon|null $review_started_at
 * @property string|null $info_request_note
 * @property \Illuminate\Support\Carbon|null $info_requested_at
 * @property string|null $decision_note
 * @property \Illuminate\Support\Carbon|null $decided_at
 * @property \Illuminate\Support\Carbon $created_at
 */
final class ProfessorApplication extends Model implements HasMedia
{
    use HasUlids;
    use InteractsWithMedia;

    public const CREDENTIALS = 'credentials';

    public const MAX_CREDENTIAL_FILES = 5;

    protected $guarded = [];

    protected $attributes = [
        'subjects' => '[]',
        'levels' => '[]',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'subjects' => 'array',
            'levels' => 'array',
            'years_experience' => 'integer',
            'submitted_at' => 'datetime',
            'review_started_at' => 'datetime',
            'info_requested_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::CREDENTIALS)
            ->useDisk((string) config('yekkola.private_disk'))
            ->acceptsMimeTypes(['application/pdf', 'image/jpeg', 'image/png']);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /**
     * @return BelongsTo<Province, $this>
     */
    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }
}
