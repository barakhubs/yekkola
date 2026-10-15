<?php

declare(strict_types=1);

namespace App\Http\Requests\Professor;

use App\Domain\Commerce\Enums\MobileMoneyRail;
use App\Domain\Shared\ValueObjects\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ChangePayoutMethodRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rail' => ['required', Rule::enum(MobileMoneyRail::class)],
            'phone' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'code' => ['required', 'string', 'digits:6'],
        ];
    }

    public function rail(): MobileMoneyRail
    {
        return MobileMoneyRail::from((string) $this->input('rail'));
    }

    public function phone(): PhoneNumber
    {
        return PhoneNumber::fromString((string) $this->input('phone'));
    }
}
