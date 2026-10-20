<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A staff decision with a note/reason (PRD-09 FR-18: destructive actions need a typed reason).
 * Pass `?required=0` semantics via the route: approve allows an empty note.
 */
final class StaffNoteRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $optional = in_array($this->route()?->getName(), ['admin.professor-applications.approve', 'admin.course-versions.approve', 'admin.refunds.approve', 'admin.payments.refund'], true);

        return [
            'note' => [$optional ? 'nullable' : 'required', 'string', 'min:3', 'max:2000'],
        ];
    }

    public function note(): ?string
    {
        $note = $this->input('note');

        return is_string($note) && $note !== '' ? $note : null;
    }
}
