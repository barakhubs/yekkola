<?php

declare(strict_types=1);

namespace App\Http\Requests\Professor;

use App\Domain\Professors\Enums\TeachingLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Autosave of the application form (PRD-02 FR-01/02). Every field is optional while drafting;
 * completeness is checked on submit.
 */
final class SaveApplicationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'nullable', 'string', 'min:3', 'max:120'],
            'subjects' => ['sometimes', 'array', 'max:10'],
            'subjects.*' => ['string', 'min:2', 'max:60', 'distinct'],
            'levels' => ['sometimes', 'array', 'max:4'],
            'levels.*' => [Rule::enum(TeachingLevel::class), 'distinct'],
            'years_experience' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:60'],
            'institutions' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'credentials' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'sample_url' => ['sometimes', 'nullable', 'url:https', 'max:500'],
            'motivation' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'province_id' => ['sometimes', 'nullable', 'ulid', Rule::exists('provinces', 'id')],
        ];
    }
}
