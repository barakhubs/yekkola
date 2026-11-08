<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

/** Notifications to mark read; leave `ids` out to mark them all. */
final class MarkNotificationsReadRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['sometimes', 'array', 'max:100'],
            'ids.*' => ['string', 'uuid'],
        ];
    }
}
