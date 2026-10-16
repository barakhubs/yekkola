<?php

declare(strict_types=1);

namespace App\Http\Requests\Commerce;

use App\Domain\Shared\Exceptions\InvalidPhoneNumber;
use App\Domain\Shared\ValueObjects\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/** What to quote or order (PRD-05 FR-02, FR-03, FR-11, FR-13): courses or a bundle, plus an optional coupon. */
class CourseSelectionRequest extends FormRequest
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
            // Buying for another student (FR-14): their phone, and optionally the number that will pay.
            'beneficiary_phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'payer_msisdn' => ['sometimes', 'nullable', 'string', 'max:20'],
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

    public function beneficiaryPhone(): ?PhoneNumber
    {
        return $this->phone('beneficiary_phone');
    }

    public function payerPhone(): ?PhoneNumber
    {
        return $this->phone('payer_msisdn');
    }

    private function phone(string $field): ?PhoneNumber
    {
        if (! $this->filled($field)) {
            return null;
        }

        try {
            return PhoneNumber::fromString((string) $this->input($field));
        } catch (InvalidPhoneNumber) {
            throw ValidationException::withMessages([$field => __('errors.phone.invalid')]);
        }
    }

    public function couponCode(): ?string
    {
        return $this->filled('coupon_code') ? (string) $this->validated('coupon_code') : null;
    }
}
