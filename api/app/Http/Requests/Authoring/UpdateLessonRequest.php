<?php

declare(strict_types=1);

namespace App\Http\Requests\Authoring;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateLessonRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_preview' => ['sometimes', 'boolean'],
        ];
    }
}
