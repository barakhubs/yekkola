<?php

declare(strict_types=1);

namespace App\Http\Requests\Authoring;

use Illuminate\Foundation\Http\FormRequest;

/** Fresh pre-signed URLs for up to 100 parts of an upload (1-based part numbers). */
final class MediaPartsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'parts' => ['required', 'array', 'min:1', 'max:100'],
            'parts.*' => ['integer', 'min:1', 'max:10000'],
        ];
    }
}
