<?php

declare(strict_types=1);

namespace App\Http\Requests\Commerce;

/**
 * A parent with no account buying for a student (PRD-05 FR-14): the student's phone is required, and a bot
 * challenge (Turnstile) must be passed.
 */
final class GuestOrderRequest extends CourseSelectionRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'beneficiary_phone' => ['required', 'string', 'max:20'],
            'bot_token' => ['nullable', 'string', 'max:4096'],
        ];
    }
}
