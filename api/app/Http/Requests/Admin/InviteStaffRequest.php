<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Shared\ValueObjects\PhoneNumber;

/** Staff access for the account with this phone number (created if it doesn't exist), PRD-09 FR-17. */
final class InviteStaffRequest extends StaffRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:32'],
            ...parent::rules(),
        ];
    }

    public function phone(): PhoneNumber
    {
        return PhoneNumber::fromString($this->string('phone')->toString());
    }
}
