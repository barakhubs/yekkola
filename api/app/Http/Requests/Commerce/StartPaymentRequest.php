<?php

declare(strict_types=1);

namespace App\Http\Requests\Commerce;

use App\Domain\Commerce\Enums\MobileMoneyRail;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Exceptions\InvalidPhoneNumber;
use App\Domain\Shared\ValueObjects\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Operator and payer number for a payment (PRD-05 FR-05). The number defaults to the buyer's own. */
final class StartPaymentRequest extends FormRequest
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
            'rail' => ['required', Rule::enum(MobileMoneyRail::class)],
            'msisdn' => ['sometimes', 'nullable', 'string', 'max:20'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (filled($this->input('msisdn')) && $this->payerOrNull() === null) {
                $validator->errors()->add('msisdn', __('errors.phone.invalid'));
            }
        }];
    }

    public function rail(): MobileMoneyRail
    {
        return MobileMoneyRail::from((string) $this->validated('rail'));
    }

    public function payer(): PhoneNumber
    {
        if (filled($this->input('msisdn'))) {
            return $this->payerOrNull() ?? throw InvalidPhoneNumber::make();
        }

        /** @var User $user */
        $user = $this->user();

        return $user->phone;
    }

    private function payerOrNull(): ?PhoneNumber
    {
        try {
            return PhoneNumber::fromString((string) $this->input('msisdn'));
        } catch (InvalidPhoneNumber) {
            return null;
        }
    }
}
