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
        $uses = (int) ($this->redemptions_count ?? $this->redemptions()->count());

        return [
            'id' => $this->id,
            'code' => $this->code,
            'scope' => $this->scope->value,
            'professor_profile_id' => $this->professor_profile_id,
            'course_id' => $this->course_id,
            // Names for the back office (loaded there): who and what the coupon applies to.
            'professor' => $this->whenLoaded('professor', fn () => $this->professor === null ? null : [
                'id' => $this->professor->id,
                'display_name' => $this->professor->display_name,
            ]),
            'course' => $this->whenLoaded('course', fn () => $this->course === null ? null : [
                'id' => $this->course->id,
                'title' => $this->course->liveVersion->title ?? $this->course->draftVersion->title ?? null,
            ]),
            'discount_type' => $this->discount_type->value,
            'value' => $this->value,
            'currency' => $this->currency,
            'funded_by' => $this->funded_by->value,
            'max_redemptions' => $this->max_redemptions,
            'per_user_limit' => $this->per_user_limit,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'is_active' => $this->is_active,
            'uses' => $uses,
            'state' => $this->state($uses),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
