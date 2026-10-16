<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use App\Domain\Commerce\Enums\CouponScope;
use App\Domain\Commerce\Enums\DiscountFunder;
use App\Domain\Commerce\Enums\DiscountType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A discount code (PRD-05 FR-11, FR-12). Codes are stored upper-case and matched case-insensitively.
 *
 * @property string $id
 * @property string $code
 * @property CouponScope $scope
 * @property string|null $professor_profile_id
 * @property string|null $course_id
 * @property DiscountType $discount_type
 * @property int $value
 * @property string|null $currency
 * @property DiscountFunder $funded_by
 * @property int|null $max_redemptions
 * @property int|null $per_user_limit
 * @property \Illuminate\Support\Carbon|null $starts_at
 * @property \Illuminate\Support\Carbon|null $ends_at
 * @property bool $is_active
 * @property string|null $created_by
 * @property \Illuminate\Support\Carbon $created_at
 */
final class Coupon extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $attributes = ['is_active' => true];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => CouponScope::class,
            'discount_type' => DiscountType::class,
            'funded_by' => DiscountFunder::class,
            'value' => 'integer',
            'max_redemptions' => 'integer',
            'per_user_limit' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public static function normalize(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    /**
     * @return HasMany<CouponRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    public function isRedeemableNow(): bool
    {
        return $this->is_active
            && ($this->starts_at === null || $this->starts_at->isPast())
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }
}
