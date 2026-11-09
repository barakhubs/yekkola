<?php

declare(strict_types=1);

namespace App\Http\Requests\Commerce;

use App\Domain\Commerce\Enums\BundleStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Bundle update (PRD-05 FR-13, PRD-09 FR-13): any field; SaveBundle checks the result as a whole. Sending only
 * `status` takes a bundle off sale even if its courses changed price since.
 */
final class UpdateBundleRequest extends FormRequest
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
            'title' => ['sometimes', 'string', 'min:3', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'price_minor' => ['sometimes', 'integer', 'min:1'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'status' => ['sometimes', Rule::enum(BundleStatus::class)],
            'course_ids' => ['sometimes', 'array', 'min:2', 'max:20'],
            'course_ids.*' => ['ulid', 'distinct'],
        ];
    }
}
