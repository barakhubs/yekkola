<?php

declare(strict_types=1);

namespace App\Http\Requests\Learning;

use Illuminate\Foundation\Http\FormRequest;

/** A lesson question: a short title and the details (PRD-06 FR-13). */
final class ThreadRequest extends FormRequest
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
            'title' => ['required', 'string', 'min:5', 'max:200'],
            'body' => ['required', 'string', 'min:5', 'max:5000'],
        ];
    }
}
