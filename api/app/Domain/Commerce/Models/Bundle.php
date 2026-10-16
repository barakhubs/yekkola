<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use App\Domain\Authoring\Models\Course;
use App\Domain\Commerce\Enums\BundleStatus;
use App\Domain\Professors\Models\ProfessorProfile;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A set of courses sold at one price (PRD-05 FR-13). Platform bundles (no professor) may mix professors;
 * revenue is split pro-rata to list prices.
 *
 * @property string $id
 * @property string|null $professor_profile_id
 * @property string $slug
 * @property string $title
 * @property string|null $description
 * @property int $price_minor
 * @property string $currency
 * @property BundleStatus $status
 */
final class Bundle extends Model
{
    use HasUlids;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => BundleStatus::class, 'price_minor' => 'integer'];
    }

    /**
     * @return BelongsToMany<Course, $this>
     */
    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'bundle_courses')->withPivot('position')->orderByPivot('position');
    }

    /**
     * @return BelongsTo<ProfessorProfile, $this>
     */
    public function professor(): BelongsTo
    {
        return $this->belongsTo(ProfessorProfile::class, 'professor_profile_id');
    }

    /**
     * On sale: active, and every course in it is listed (PRD-05 FR-13).
     *
     * @param  Builder<self>  $query
     */
    public function scopeOnSale(Builder $query): void
    {
        $query->where('status', BundleStatus::Active)
            ->whereDoesntHave('courses', fn (Builder $c) => $c->whereNotIn('courses.id', Course::query()->listed()->select('courses.id')));
    }

    public function price(): Money
    {
        return Money::of($this->price_minor, Currency::from($this->currency));
    }
}
