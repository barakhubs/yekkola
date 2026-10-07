<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Http\Requests\Concerns\HasPhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

final class ChangePhoneRequest extends FormRequest
{
    use HasPhoneNumber;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:32'],
            'code' => ['required', 'string', 'digits:6'],
        ];
    }
}
