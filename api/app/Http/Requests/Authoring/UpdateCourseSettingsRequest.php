<?php

declare(strict_types=1);

namespace App\Http\Requests\Authoring;

use App\Domain\Authoring\Enums\Audience;
use App\Domain\Authoring\Enums\CourseLevel;
use App\Domain\Shared\Enums\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Course-level settings (PRD-03 FR-09/15): apply immediately, no review.
 */
final class UpdateCourseSettingsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category_id' => ['sometimes', 'nullable', 'ulid', Rule::exists('categories', 'id')->where('is_visible', true)],
            'language' => ['sometimes', Rule::in(['fr', 'en'])],
            'level' => ['sometimes', Rule::enum(CourseLevel::class)],
            'audience' => ['sometimes', 'array', 'max:4'],
            'audience.*' => ['string', Rule::enum(Audience::class), 'distinct'],
            'is_free' => ['sometimes', 'boolean'],
            'price_minor' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'currency' => ['sometimes', 'nullable', Rule::enum(Currency::class)],
        ];
    }
}
