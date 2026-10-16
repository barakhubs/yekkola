<?php

declare(strict_types=1);

namespace App\Http\Requests\Commerce;

use Illuminate\Foundation\Http\FormRequest;

/** Courses to quote or order (PRD-05 FR-02, FR-03). */
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
            'course_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'course_ids.*' => ['required', 'ulid', 'distinct'],
        ];
    }

    /**
     * @return list<string>
     */
    public function courseIds(): array
    {
        return array_values(array_map('strval', (array) $this->validated('course_ids')));
    }
}
