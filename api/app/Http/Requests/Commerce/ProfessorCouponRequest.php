<?php

declare(strict_types=1);

namespace App\Http\Requests\Commerce;

use App\Domain\Commerce\Enums\DiscountType;
use App\Domain\Commerce\Models\Coupon;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A professor's own coupon (PRD-05 FR-12): one of their courses (`course_id`) or all of them (no `course_id`).
 * Scope, owner and funder are set by the server (always professor-funded). Updates use UpdateCouponRequest.
 */
final class ProfessorCouponRequest extends FormRequest
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
        return [
            ...UpdateCouponRequest::OPERATIONAL,
            'code' => ['required', 'string', 'min:6', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/', function (string $attribute, mixed $value, Closure $fail): void {
                if (Coupon::query()->where('code', Coupon::normalize((string) $value))->exists()) {
                    $fail(__('validation.unique', ['attribute' => $attribute]));
                }
            }],
            'scope' => ['prohibited'],
            'professor_profile_id' => ['prohibited'],
            'course_id' => ['sometimes', 'nullable', 'ulid'],
            'discount_type' => ['required', Rule::enum(DiscountType::class)],
            'value' => ['required', 'integer', 'min:1', Rule::when($this->input('discount_type') === 'percent', ['max:100'])],
            'currency' => ['required_if:discount_type,fixed', 'nullable', 'string', 'size:3'],
            'funded_by' => ['prohibited'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after:starts_at'],
        ];
    }
}
