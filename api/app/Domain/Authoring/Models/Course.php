<?php

declare(strict_types=1);

namespace App\Domain\Authoring\Models;

use App\Domain\Authoring\Enums\CourseLevel;
use App\Domain\Authoring\Enums\CourseStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Commerce\Models\Bundle;
use App\Domain\Professors\Models\ProfessorProfile;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

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
 * @property int|null $featured_position
 * @property \Illuminate\Support\Carbon|null $published_at
 * @property int $enrollment_count
 * @property float $rating_avg
 * @property int $rating_count
 * @property int $total_duration_seconds
 * @property \Illuminate\Support\Carbon $created_at
 */
final class Course extends Model
{
    use HasUlids;
    use Searchable;
    use SoftDeletes;

    /** PRD-04 BR-02: below this many reviews the course shows "Nouveau" instead of a rating. */
    public const MIN_REVIEWS_FOR_RATING = 3;

    protected $guarded = [];

    protected $attributes = [
        'audience' => '[]',
        'language' => 'fr',
        'level' => 'all',
        'is_free' => false,
        'enrollment_count' => 0,
        'rating_count' => 0,
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
            'featured_position' => 'integer',
            'rating_avg' => 'float',
            'rating_count' => 'integer',
            'price_minor' => 'integer',
            'revenue_share_bps' => 'integer',
            'published_at' => 'datetime',
            'enrollment_count' => 'integer',
            'total_duration_seconds' => 'integer',
        ];
    }

    /**
     * Bundles that include this course (PRD-05 FR-13).
     *
     * @return BelongsToMany<Bundle, $this>
     */
    public function bundles(): BelongsToMany
    {
        return $this->belongsToMany(Bundle::class, 'bundle_courses');
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

    /** Null until the course has enough reviews (BR-02). */
    public function publicRating(): ?float
    {
        return $this->rating_count >= self::MIN_REVIEWS_FOR_RATING ? round($this->rating_avg, 1) : null;
    }

    /**
     * What the public catalogue may show (PRD-04 BR-01, FR-12): published, with a live version, from an
     * active professor whose account is active.
     *
     * @param  Builder<self>  $query
     */
    public function scopeListed(Builder $query): void
    {
        $query->where('status', CourseStatus::Published)
            ->whereNotNull('live_version_id')
            ->whereHas('professor', fn (Builder $q) => $q->public());
    }

    public function isListed(): bool
    {
        return $this->status === CourseStatus::Published
            && $this->live_version_id !== null
            && $this->professor->isActive()
            && $this->professor->user?->isActive() === true;
    }

    public function shouldBeSearchable(): bool
    {
        return $this->isListed();
    }

    /** Only what the index holds or lists by; price and other filters are read from Postgres. */
    public function searchIndexShouldBeUpdated(): bool
    {
        return $this->wasRecentlyCreated || $this->wasChanged(['status', 'live_version_id', 'category_id', 'audience', 'deleted_at']);
    }

    /**
     * Text Meilisearch ranks on (PRD-04 FR-03). Filters and sorts are applied in Postgres.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $live = $this->liveVersion;
        $category = $this->category;

        return [
            'id' => $this->id,
            'title' => $live?->title,
            'subtitle' => $live?->subtitle,
            'description' => $live?->description,
            'outcomes' => $live->outcomes ?? [],
            'professor_name' => $this->professor->display_name,
            'category_names' => array_values(array_filter([
                ...array_values($category?->getTranslations('name') ?? []),
                ...array_values($category?->parent?->getTranslations('name') ?? []),
            ])),
            'audience' => $this->audience,
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query->with('liveVersion', 'professor.user', 'category.parent');
    }

    /**
     * @param  Collection<int, self>  $models
     * @return Collection<int, self>
     */
    public function makeSearchableUsing(Collection $models): Collection
    {
        return $models->loadMissing('liveVersion', 'professor.user', 'category.parent');
    }
}
