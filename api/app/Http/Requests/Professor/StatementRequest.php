<?php

declare(strict_types=1);

namespace App\Http\Requests\Professor;

use App\Domain\Finance\Enums\LedgerTransactionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StatementRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['nullable', Rule::enum(LedgerTransactionType::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function type(): ?string
    {
        return $this->filled('type') ? $this->string('type')->toString() : null;
    }

    public function from(): ?string
    {
        return $this->filled('from') ? $this->string('from')->toString() : null;
    }

    public function to(): ?string
    {
        return $this->filled('to') ? $this->string('to')->toString() : null;
    }
}
