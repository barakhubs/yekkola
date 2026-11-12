<?php

declare(strict_types=1);

namespace App\Http\Requests\Protection;

use Illuminate\Foundation\Http\FormRequest;

/** Which rendition to download (PRD-07 FR-08); omitted = our lowest. */
final class OfflineLicenseRequest extends FormRequest
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
            'rendition' => ['nullable', 'string', 'regex:/^[a-z0-9]{1,16}$/'],
        ];
    }
}
