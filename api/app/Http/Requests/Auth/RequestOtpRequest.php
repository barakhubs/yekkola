<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\HasPhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

final class RequestOtpRequest extends FormRequest
{
    use HasPhoneNumber;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:32'],
            // Cloudflare Turnstile token — required for web (browser) requests.
            'bot_token' => ['nullable', 'string', 'max:4096'],
        ];
    }
}
