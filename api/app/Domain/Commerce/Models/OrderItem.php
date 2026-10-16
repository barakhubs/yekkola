<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use App\Domain\Authoring\Models\Course;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function price(): Money
    {
        return Money::of($this->price_minor, Currency::from($this->currency));
    }
}
