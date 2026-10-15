<?php

declare(strict_types=1);

namespace App\Domain\Authoring\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Quiz settings for a quiz lesson (PRD-03 FR-07). Correct answers never leave the API before a
 * student submits an attempt.
 *
 * @property string $id
 * @property string $lesson_id
 * @property int $pass_mark_pct
 * @property int|null $time_limit_seconds
 * @property int|null $max_attempts
 * @property bool $shuffle_questions
 */
final class Quiz extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $attributes = [
        'pass_mark_pct' => 50,
        'shuffle_questions' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pass_mark_pct' => 'integer',
            'time_limit_seconds' => 'integer',
            'max_attempts' => 'integer',
            'shuffle_questions' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * @return HasMany<QuizQuestion, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(QuizQuestion::class)->orderBy('position');
    }
}
