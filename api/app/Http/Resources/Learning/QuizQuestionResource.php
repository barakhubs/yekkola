<?php

declare(strict_types=1);

namespace App\Http\Resources\Learning;

use App\Domain\Authoring\Models\QuizOption;
use App\Domain\Authoring\Models\QuizQuestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One question of a quiz attempt (PRD-06 FR-07). Before submission `chosen`, `correct` and `explanation` are
 * null — the answer key never reaches the student before they submit.
 *
 * @mixin QuizQuestion
 */
final class QuizQuestionResource extends JsonResource
{
    /**
     * @param  list<string>|null  $chosen  The student's options once submitted; null while the attempt is open.
     */
    public function __construct(QuizQuestion $question, private readonly bool $submitted, private readonly ?array $chosen)
    {
        parent::__construct($question);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'type' => $this->type->value,
            'prompt' => (string) $this->prompt,
            'points' => (int) $this->points,
            'options' => $this->options->map(fn (QuizOption $o) => ['id' => (string) $o->id, 'text' => (string) $o->text])->values()->all(),
            'chosen' => $this->submitted ? collect($this->chosen ?? [])->map(fn ($id) => (string) $id)->values()->all() : null,
            'correct' => $this->submitted ? $this->options->where('is_correct', true)->pluck('id')->map(fn ($id) => (string) $id)->values()->all() : null,
            'explanation' => $this->submitted ? $this->explanation : null,
        ];
    }
}
