<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Identity\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UserStatusRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(UserStatus::class)],
            /** Why: kept in the audit log. */
            'note' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    public function status(): UserStatus
    {
        return UserStatus::from($this->string('status')->toString());
    }
}
