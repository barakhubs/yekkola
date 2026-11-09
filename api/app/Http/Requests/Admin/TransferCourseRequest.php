<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** Move a course to another professor, with the reason kept in the audit log (PRD-09 FR-08). */
final class TransferCourseRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'professor_profile_id' => ['required', 'ulid'],
            'note' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }
}
