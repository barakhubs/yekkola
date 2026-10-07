<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateProfileRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:100'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($this->user()?->getAuthIdentifier())],
            'locale' => ['sometimes', 'required', Rule::in(['fr', 'en'])],
            'province_id' => ['sometimes', 'nullable', 'ulid', Rule::exists('provinces', 'id')],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
