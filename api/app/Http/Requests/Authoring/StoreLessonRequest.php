<?php

declare(strict_types=1);

namespace App\Http\Requests\Authoring;

use App\Domain\Authoring\Enums\LessonType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreLessonRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'section_id' => ['required', 'ulid'],
            'type' => ['required', Rule::enum(LessonType::class)],
            'title' => ['required', 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_preview' => ['sometimes', 'boolean'],
        ];
    }
}
