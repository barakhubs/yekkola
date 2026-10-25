<?php

declare(strict_types=1);

namespace App\Http\Resources\Learning;

use App\Domain\Authoring\Models\QuizQuestion;
use App\Domain\Learning\Models\QuizAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A quiz attempt (PRD-06 FR-07). While open: the questions in attempt order, with `chosen`, `correct` and
 * `explanation` null. Once submitted: score, pass/fail, and per question what I chose, what was right, and why.
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
            // Count down from this rather than the device clock (it can be minutes off, PRD-06 §7).
            'seconds_left' => $this->secondsLeft(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'pass_mark_pct' => $this->quiz->pass_mark_pct,
            'score_pct' => $this->score_pct,
            'passed' => $this->passed,
            'timed_out' => $this->timed_out,
            'questions' => collect($this->question_order)
                ->map(fn (string $id) => $questions->get($id))
                ->filter()
                ->map(fn (QuizQuestion $q) => new QuizQuestionResource($q, $submitted, $submitted ? ($this->answers[$q->id] ?? []) : null))
                ->values(),
        ];
    }

    /** Until the time limit (the server adds a short grace after it); null when untimed or submitted. */
    private function secondsLeft(): ?int
    {
        if ($this->quiz->time_limit_seconds === null || $this->submitted_at !== null) {
            return null;
        }

        return max(0, (int) now()->diffInSeconds($this->started_at->copy()->addSeconds($this->quiz->time_limit_seconds), false));
    }
}
