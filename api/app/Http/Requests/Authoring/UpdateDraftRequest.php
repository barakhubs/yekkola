<?php

declare(strict_types=1);

namespace App\Http\Requests\Authoring;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Draft details (PRD-03 FR-02). Completeness is checked on submit.
 */
final class UpdateDraftRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'min:5', 'max:80'],
            'subtitle' => ['sometimes', 'nullable', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'outcomes' => ['sometimes', 'array', 'max:8'],
            'outcomes.*' => ['string', 'min:3', 'max:160'],
            'requirements' => ['sometimes', 'array', 'max:10'],
            'requirements.*' => ['string', 'min:3', 'max:160'],
            'changelog' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
