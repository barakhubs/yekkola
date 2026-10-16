<?php

declare(strict_types=1);

namespace App\Http\Resources\Commerce;

use App\Domain\Commerce\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A coupon with its usage (PRD-09 FR-13). `uses` counts paid redemptions.
 *
 * @mixin Coupon
 */
final class CouponResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'scope' => $this->scope->value,
            'professor_profile_id' => $this->professor_profile_id,
            'course_id' => $this->course_id,
            'discount_type' => $this->discount_type->value,
            'value' => $this->value,
            'currency' => $this->currency,
            'funded_by' => $this->funded_by->value,
            'max_redemptions' => $this->max_redemptions,
            'per_user_limit' => $this->per_user_limit,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'is_active' => $this->is_active,
            'uses' => (int) ($this->redemptions_count ?? $this->redemptions()->count()),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
