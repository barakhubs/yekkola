<?php

declare(strict_types=1);

namespace App\Http\Requests\Commerce;

use App\Domain\Commerce\Actions\SaveCoupon;
use App\Domain\Commerce\Enums\CouponScope;
use App\Domain\Commerce\Enums\DiscountFunder;
use App\Domain\Commerce\Enums\DiscountType;
use App\Domain\Commerce\Models\Coupon;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Coupon create (all fields) or update (operational fields only) — staff (PRD-09 FR-13) and professors
 * (PRD-05 FR-12; scope and funder are then set by the server).
 */
final class CouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $operational = [
            'is_active' => ['sometimes', 'boolean'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'max_redemptions' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'per_user_limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ];

        if ($this->isMethod('PATCH')) {
            return $operational;
        }

        $byProfessor = $this->routeIs('professor.*');

        return [
            ...$operational,
            'code' => ['required', 'string', 'min:4', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/', function (string $attribute, mixed $value, Closure $fail): void {
                if (Coupon::query()->where('code', Coupon::normalize((string) $value))->exists()) {
                    $fail(__('validation.unique', ['attribute' => $attribute]));
                }
            }],
            'scope' => $byProfessor ? ['prohibited'] : ['required', Rule::enum(CouponScope::class)],
            'professor_profile_id' => $byProfessor ? ['prohibited'] : ['required_if:scope,professor', 'nullable', 'ulid', Rule::exists('professor_profiles', 'id')],
            'course_id' => [$byProfessor ? 'sometimes' : 'required_if:scope,course', 'nullable', 'ulid'],
            'discount_type' => ['required', Rule::enum(DiscountType::class)],
            'value' => ['required', 'integer', 'min:1', Rule::when($this->input('discount_type') === 'percent', ['max:100'])],
            'currency' => ['required_if:discount_type,fixed', 'nullable', 'string', 'size:3'],
            'funded_by' => $byProfessor ? ['prohibited'] : ['required', Rule::enum(DiscountFunder::class)],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after:starts_at'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function coupon(): array
    {
        return $this->isMethod('PATCH') ? $this->safe()->only(SaveCoupon::UPDATABLE) : $this->validated();
    }
}
