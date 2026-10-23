<?php

declare(strict_types=1);

namespace App\Http\Requests\Learning;

use Illuminate\Foundation\Http\FormRequest;

/** The professor's public reply to a review (PRD-06 FR-12); null removes it. */
final class ReviewReplyRequest extends FormRequest
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
            'reply' => ['present', 'nullable', 'string', 'max:2000'],
        ];
    }
}
