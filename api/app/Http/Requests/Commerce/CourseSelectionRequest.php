<?php

declare(strict_types=1);

namespace App\Http\Requests\Commerce;

use Illuminate\Foundation\Http\FormRequest;

/** What to quote or order (PRD-05 FR-02, FR-03, FR-11, FR-13): courses or a bundle, plus an optional coupon. */
final class CourseSelectionRequest extends FormRequest
{
    public const MAX_ITEMS = 20;

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
            'course_ids' => ['required_without:bundle_id', 'prohibits:bundle_id', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'course_ids.*' => ['required', 'ulid', 'distinct'],
            'bundle_id' => ['required_without:course_ids', 'ulid'],
            'coupon_code' => ['sometimes', 'nullable', 'string', 'max:40'],
        ];
    }

    /**
     * @return list<string>
     */
    public function courseIds(): array
    {
        return array_values(array_map('strval', (array) $this->validated('course_ids', [])));
    }

    public function bundleId(): ?string
    {
        return $this->filled('bundle_id') ? (string) $this->validated('bundle_id') : null;
    }

    public function couponCode(): ?string
    {
        return $this->filled('coupon_code') ? (string) $this->validated('coupon_code') : null;
    }
}
