<?php

declare(strict_types=1);

namespace App\Http\Requests\Authoring;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Full curriculum order: every section and lesson exactly once (PRD-03 FR-03).
 */
final class ReorderCurriculumRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sections' => ['required', 'array', 'min:1'],
            'sections.*.id' => ['required', 'ulid'],
            'sections.*.lessons' => ['present', 'array'],
            'sections.*.lessons.*' => ['ulid'],
        ];
    }
}
