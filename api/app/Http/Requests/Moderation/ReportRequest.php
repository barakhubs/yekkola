<?php

declare(strict_types=1);

namespace App\Http\Requests\Moderation;

use App\Domain\Moderation\Enums\ReportReason;
use App\Domain\Moderation\Enums\ReportTarget;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** What is reported and why (PRD-06 FR-14); details are required for "other". */
final class ReportRequest extends FormRequest
{
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
            'type' => ['required', Rule::enum(ReportTarget::class)],
            'id' => ['required', 'string', 'ulid'],
            'reason' => ['required', Rule::enum(ReportReason::class)],
            'details' => ['nullable', 'string', 'max:1000', 'required_if:reason,'.ReportReason::Other->value],
        ];
    }
}
