<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Authoring\Models\Course;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/**
 * Catalogue category (two levels max), names in French and English. Admin-managed (PRD-09 FR-09);
 * seeded from docs/catalogue-categories.md.
 *
 * @property string $id
 * @property string|null $parent_id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property string|null $icon
 * @property int $position
 * @property bool $is_visible
 */
final class Category extends Model
{
    use HasTranslations;
    use HasUlids;

    /** @var list<string> */
    public array $translatable = ['name', 'description'];

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_visible' => 'boolean', 'position' => 'integer'];
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    /**
     * Courses filed directly under this category.
     *
     * @return HasMany<Course, $this>
     */
    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->where('is_visible', true);
    }
}
