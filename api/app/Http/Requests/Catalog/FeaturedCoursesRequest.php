<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

final class FeaturedCoursesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route requires catalog.manage
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'course_ids' => ['present', 'array', 'max:'.(int) config('yekkola.catalogue.max_featured')],
            'course_ids.*' => ['ulid', 'distinct'],
        ];
    }

    /**
     * @return list<string>
     */
    public function courseIds(): array
    {
        return array_values(array_map('strval', (array) $this->validated('course_ids', [])));
    }
}
