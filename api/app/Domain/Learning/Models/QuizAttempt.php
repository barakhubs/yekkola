<?php

declare(strict_types=1);

namespace App\Domain\Learning\Models;

use App\Domain\Authoring\Models\Quiz;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One go at a quiz (PRD-06 FR-07), tied to the quiz version it started on.
 *
 * @property string $id
 * @property string $user_id
 * @property string $quiz_id
 * @property string $course_id
 * @property string $lesson_uid
 * @property list<string> $question_order
 * @property array<string, list<string>>|null $answers
 * @property \Illuminate\Support\Carbon $started_at
 * @property \Illuminate\Support\Carbon|null $deadline_at
 * @property \Illuminate\Support\Carbon|null $submitted_at
 * @property int|null $score_pct
 * @property bool|null $passed
 * @property bool $timed_out
 * @property string|null $device_id
 */
final class QuizAttempt extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $attributes = ['timed_out' => false];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'question_order' => 'array',
            'answers' => 'array',
            'started_at' => 'datetime',
            'deadline_at' => 'datetime',
            'submitted_at' => 'datetime',
            'score_pct' => 'integer',
            'passed' => 'boolean',
            'timed_out' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Quiz, $this>
     */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function isOpen(): bool
    {
        return $this->submitted_at === null && ($this->deadline_at === null || $this->deadline_at->isFuture());
    }
}
