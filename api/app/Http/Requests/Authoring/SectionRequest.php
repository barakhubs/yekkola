<?php

declare(strict_types=1);

namespace App\Http\Requests\Authoring;

use Illuminate\Foundation\Http\FormRequest;

final class SectionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:2', 'max:120'],
        ];
    }
}
