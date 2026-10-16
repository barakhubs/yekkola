<?php

declare(strict_types=1);

namespace App\Http\Requests\Commerce;

use App\Domain\Commerce\Enums\BundleStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Bundle create/update (PRD-05 FR-13, PRD-09 FR-13). */
final class BundleRequest extends FormRequest
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
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'min:3', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'price_minor' => [$required, 'integer', 'min:1'],
            'currency' => [$required, 'string', 'size:3'],
            'status' => ['sometimes', Rule::enum(BundleStatus::class)],
            'course_ids' => [$required, 'array', 'min:2', 'max:20'],
            'course_ids.*' => ['ulid', 'distinct'],
        ];
    }
}
