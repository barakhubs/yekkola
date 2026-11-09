<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** Give an account access to a course, with the reason kept in the audit log (PRD-09 FR-04). */
final class GrantEnrollmentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'course_id' => ['required', 'ulid', 'exists:courses,id'],
            'note' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }
}
