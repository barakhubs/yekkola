<?php

declare(strict_types=1);

namespace App\Domain\Authoring\Models;

use App\Domain\Authoring\Enums\CourseLevel;
use App\Domain\Authoring\Enums\CourseStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Professors\Models\ProfessorProfile;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The sellable unit (PRD-03). Course-level settings (category, language, level, price) apply immediately;
 * content lives in versions: at most one draft (being edited / in review) and one live version.
 *
 * @property string $id
 * @property string $professor_profile_id
 * @property string $slug
 * @property CourseStatus $status
 * @property string|null $live_version_id
 * @property string|null $draft_version_id
 * @property string|null $category_id
 * @property string $language
 * @property CourseLevel $level
 * @property list<string> $audience
 * @property bool $is_free
 * @property int|null $price_minor
 * @property string|null $currency
 * @property int|null $revenue_share_bps
 * @property bool $is_featured
 * @property \Illuminate\Support\Carbon|null $published_at
 * @property int $enrollment_count
 * @property int $total_duration_seconds
 * @property \Illuminate\Support\Carbon $created_at
 */
final class Course extends Model
{
    use HasUlids;
    use SoftDeletes;

    protected $guarded = [];

    protected $attributes = [
        'audience' => '[]',
        'language' => 'fr',
        'level' => 'all',
        'is_free' => false,
        'is_featured' => false,
        'enrollment_count' => 0,
        'total_duration_seconds' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CourseStatus::class,
            'level' => CourseLevel::class,
            'audience' => 'array',
            'is_free' => 'boolean',
            'is_featured' => 'boolean',
            'price_minor' => 'integer',
            'revenue_share_bps' => 'integer',
            'published_at' => 'datetime',
            'enrollment_count' => 'integer',
            'total_duration_seconds' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ProfessorProfile, $this>
     */
    public function professor(): BelongsTo
    {
        return $this->belongsTo(ProfessorProfile::class, 'professor_profile_id');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<CourseVersion, $this>
     */
    public function liveVersion(): BelongsTo
    {
        return $this->belongsTo(CourseVersion::class, 'live_version_id');
    }

    /**
     * @return BelongsTo<CourseVersion, $this>
     */
    public function draftVersion(): BelongsTo
    {
        return $this->belongsTo(CourseVersion::class, 'draft_version_id');
    }

    /**
     * @return HasMany<CourseVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(CourseVersion::class);
    }

    /** Null for free courses or when no price is set yet. */
    public function price(): ?Money
    {
        if ($this->is_free || $this->price_minor === null || $this->currency === null) {
            return null;
        }

        return Money::of($this->price_minor, Currency::from($this->currency));
    }

    public function hasPricing(): bool
    {
        return $this->is_free || $this->price() !== null;
    }

    public function wasEverPublished(): bool
    {
        return $this->live_version_id !== null;
    }
}
