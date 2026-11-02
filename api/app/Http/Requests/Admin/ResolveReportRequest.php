<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Moderation\Enums\ReportAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** How staff resolve a report (PRD-09 FR-10): a note is optional to dismiss, required for any other action (FR-18). */
final class ResolveReportRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::enum(ReportAction::class)],
            'note' => ['nullable', 'string', 'min:3', 'max:2000', 'required_unless:action,'.ReportAction::Dismiss->value],
        ];
    }

    public function action(): ReportAction
    {
        return ReportAction::from((string) $this->input('action'));
    }

    public function note(): ?string
    {
        $note = $this->input('note');

        return is_string($note) && $note !== '' ? $note : null;
    }
}
