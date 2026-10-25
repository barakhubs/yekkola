<?php

declare(strict_types=1);

namespace App\Http\Requests\Learning;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/** Answers to a quiz attempt: question id => chosen option ids (PRD-06 FR-07). */
final class SubmitQuizRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Each value is checked by a closure rather than `answers.*` rules, so the OpenAPI spec keeps the
     * map shape (with `answers.*` it would describe a list).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /**
             * Question id => chosen option ids.
             *
             * @var array<string, list<string>>
             */
            'answers' => ['present', 'array', 'max:200', $this->eachIsAListOfIds(...)],
        ];
    }

    /**
     * @param  Closure(string): void  $fail
     */
    private function eachIsAListOfIds(string $attribute, mixed $value, Closure $fail): void
    {
        foreach ((array) $value as $chosen) {
            if (! is_array($chosen) || count($chosen) > 20 || ! array_is_list($chosen)) {
                $fail(__('validation.array', ['attribute' => $attribute]));

                return;
            }
            foreach ($chosen as $id) {
                if (! is_string($id) || strlen($id) !== 26) {
                    $fail(__('validation.size.string', ['attribute' => $attribute, 'size' => 26]));

                    return;
                }
            }
        }
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
