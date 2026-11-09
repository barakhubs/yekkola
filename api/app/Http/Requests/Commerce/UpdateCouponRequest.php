<?php

declare(strict_types=1);

namespace App\Http\Requests\Commerce;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Coupon update (PRD-09 FR-13, PRD-05 FR-12): only the operational fields — active, end date, limits. What a
 * coupon is worth never changes once created (SaveCoupon::UPDATABLE).
 */
final class UpdateCouponRequest extends FormRequest
{
    public const OPERATIONAL = [
        'is_active' => ['sometimes', 'boolean'],
        'ends_at' => ['sometimes', 'nullable', 'date'],
        'max_redemptions' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],
        'per_user_limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::OPERATIONAL;
    }
}
