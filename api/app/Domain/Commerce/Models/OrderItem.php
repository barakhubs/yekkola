<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use App\Domain\Authoring\Models\Course;
use App\Domain\Learning\Models\Enrollment;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One course in an order, with the snapshot the ledger uses (PRD-05 FR-03, PRD-08 BR-01).
 *
 * @property string $id
 * @property string $order_id
 * @property string $course_id
 * @property string $professor_profile_id
 * @property string $title_snapshot
 * @property int $list_price_minor
 * @property int $discount_minor
 * @property int $price_minor
 * @property string|null $discount_funded_by
 * @property int $revenue_share_bps
 * @property string $currency
 * @property \Illuminate\Support\Carbon|null $granted_at
 */
final class OrderItem extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $attributes = ['discount_minor' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'list_price_minor' => 'integer',
            'discount_minor' => 'integer',
            'price_minor' => 'integer',
            'revenue_share_bps' => 'integer',
            'granted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class)->withTrashed();
    }

    /**
     * The access this item granted, while it is still active.
     *
     * @return HasOne<Enrollment, $this>
     */
    public function activeEnrollment(): HasOne
    {
        return $this->hasOne(Enrollment::class)->active();
    }

    /**
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function price(): Money
    {
        return Money::of($this->price_minor, Currency::from($this->currency));
    }
}
