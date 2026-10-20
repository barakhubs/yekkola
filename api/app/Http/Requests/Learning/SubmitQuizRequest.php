<?php

declare(strict_types=1);

namespace App\Http\Requests\Learning;

use Illuminate\Foundation\Http\FormRequest;

/** Answers to a quiz attempt: question id => chosen option ids (PRD-06 FR-07). */
final class SubmitQuizRequest extends FormRequest
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
            'answers' => ['present', 'array', 'max:200'],
            'answers.*' => ['array', 'max:20'],
            'answers.*.*' => ['string', 'size:26'],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public function answers(): array
    {
        /** @var array<string, list<string>> */
        return (array) $this->validated('answers', []);
    }
}
