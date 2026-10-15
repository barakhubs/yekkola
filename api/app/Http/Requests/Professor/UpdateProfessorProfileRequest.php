<?php

declare(strict_types=1);

namespace App\Http\Requests\Professor;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateProfessorProfileRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'display_name' => ['sometimes', 'required', 'string', 'min:3', 'max:120'],
            'slug' => ['sometimes', 'required', 'string', 'min:3', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'headline' => ['sometimes', 'nullable', 'string', 'max:120'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'subjects' => ['sometimes', 'array', 'max:10'],
            'subjects.*' => ['string', 'min:2', 'max:60', 'distinct'],
            'links' => ['sometimes', 'array', 'max:5'],
            'links.*.label' => ['required', 'string', 'max:40'],
            'links.*.url' => ['required', 'url:https', 'max:300'],
            'province_id' => ['sometimes', 'nullable', 'ulid', Rule::exists('provinces', 'id')],
        ];
    }
}
