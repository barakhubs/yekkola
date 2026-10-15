<?php

declare(strict_types=1);

namespace App\Http\Requests\Authoring;

use App\Domain\Authoring\Enums\QuestionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Full quiz definition, replaced atomically (PRD-03 FR-07).
 */
final class QuizRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'pass_mark_pct' => ['required', 'integer', 'min:1', 'max:100'],
            'time_limit_seconds' => ['nullable', 'integer', 'min:60', 'max:14400'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:100'],
            'shuffle_questions' => ['sometimes', 'boolean'],
            'questions' => ['required', 'array', 'min:1', 'max:200'],
            'questions.*.type' => ['required', Rule::enum(QuestionType::class)],
            'questions.*.prompt' => ['required', 'string', 'min:3', 'max:2000'],
            'questions.*.explanation' => ['nullable', 'string', 'max:4000'],
            'questions.*.points' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'questions.*.options' => ['required', 'array', 'min:2', 'max:8'],
            'questions.*.options.*.text' => ['required', 'string', 'min:1', 'max:500'],
            'questions.*.options.*.is_correct' => ['required', 'boolean'],
        ];
    }
}
