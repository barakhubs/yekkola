<?php

declare(strict_types=1);

namespace App\Http\Resources\Learning;

use App\Domain\Authoring\Models\QuizOption;
use App\Domain\Authoring\Models\QuizQuestion;
use App\Domain\Learning\Models\QuizAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A quiz attempt (PRD-06 FR-07). While open: the questions in attempt order, without answers or
 * explanations. Once submitted: score, pass/fail, and per question what I chose, what was right, and why.
 *
 * @mixin QuizAttempt
 */
final class QuizAttemptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $questions = $this->quiz->questions->keyBy('id');
        $submitted = $this->submitted_at !== null;

        return [
            'id' => $this->id,
            'lesson_uid' => $this->lesson_uid,
            'started_at' => $this->started_at->toIso8601String(),
            // Count down to this; the server accepts answers for a short grace period after it (network slack).
            'ends_at' => $this->quiz->time_limit_seconds === null ? null : $this->started_at->copy()->addSeconds($this->quiz->time_limit_seconds)->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'pass_mark_pct' => $this->quiz->pass_mark_pct,
            'score_pct' => $this->score_pct,
            'passed' => $this->passed,
            'timed_out' => $this->timed_out,
            'questions' => collect($this->question_order)
                ->map(fn (string $id) => $questions->get($id))
                ->filter()
                ->map(fn (QuizQuestion $q) => [
                    'id' => $q->id,
                    'type' => $q->type->value,
                    'prompt' => $q->prompt,
                    'points' => $q->points,
                    'options' => $q->options->map(fn (QuizOption $o) => ['id' => $o->id, 'text' => $o->text])->values()->all(),
                    ...($submitted ? [
                        'chosen' => $this->answers[$q->id] ?? [],
                        'correct' => $q->options->where('is_correct', true)->pluck('id')->values()->all(),
                        'explanation' => $q->explanation,
                    ] : []),
                ])->values()->all(),
        ];
    }
}
