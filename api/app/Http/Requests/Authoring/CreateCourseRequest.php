<?php

declare(strict_types=1);

namespace App\Http\Requests\Authoring;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateCourseRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:5', 'max:80'],
            'language' => ['sometimes', Rule::in(['fr', 'en'])],
            'category_id' => ['sometimes', 'nullable', 'ulid', Rule::exists('categories', 'id')],
        ];
    }
}
