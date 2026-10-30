<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AdjustmentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'professor_profile_id' => ['required', 'string', 'exists:professor_profiles,id'],
            'currency' => ['required', Rule::enum(Currency::class)],
            'amount_minor' => ['required', 'integer', 'min:1', 'max:100000000000'],
            /** `credit`: the platform owes the professor more; `debit`: taken back. */
            'direction' => ['required', Rule::in(['credit', 'debit'])],
            /** Shown to the professor on their statement. */
            'label' => ['required', 'string', 'min:3', 'max:200'],
            /** Internal, kept in the audit log. */
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    public function amount(): Money
    {
        return Money::of($this->integer('amount_minor'), Currency::from($this->string('currency')->toString()));
    }

    public function toProfessor(): bool
    {
        return $this->input('direction') === 'credit';
    }
}
