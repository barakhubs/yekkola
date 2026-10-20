<?php

declare(strict_types=1);

namespace App\Http\Resources\Learning;

use App\Domain\Authoring\Models\Course;
use App\Domain\Authoring\Models\Lesson;
use App\Domain\Authoring\Models\Section;
use App\Domain\Learning\Models\Enrollment;
use App\Domain\Learning\Models\LessonProgress;
use App\Domain\Learning\Models\QuizAttempt;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * The learning view (PRD-06 FR-02): the live curriculum with my status per lesson, where to continue, and
 * quiz facts (never the answers). Lesson content itself comes from the playback/document endpoints.
 *
 * @mixin Course
 */
final class LearningViewResource extends JsonResource
{
    /**
     * @param  Collection<string, LessonProgress>  $progress  by lesson uid
     * @param  EloquentCollection<int, QuizAttempt>  $attempts  mine, on this course's quizzes
     */
    public function __construct(Course $course, private readonly Enrollment $enrollment, private readonly Collection $progress, private readonly EloquentCollection $attempts)
    {
        parent::__construct($course);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $live = $this->liveVersion;
        $next = null;

        $sections = $live->sections->map(function (Section $section) use (&$next): array {
            return [
                'id' => $section->id,
                'title' => $section->title,
                'lessons' => $section->lessons->map(function (Lesson $lesson) use (&$next): array {
                    $mine = $this->progress->get($lesson->uid);
                    if ($next === null && ! $mine?->isCompleted()) {
                        $next = $lesson->uid;
                    }

                    return [
                        'uid' => $lesson->uid,
                        'type' => $lesson->type->value,
                        'title' => $lesson->title,
                        'duration_seconds' => $lesson->duration_seconds,
                        'status' => $mine?->status->value ?? 'not_started',
                        'position_seconds' => $mine->position_seconds ?? 0,
                        'quiz' => $lesson->quiz === null ? null : $this->quizFacts($lesson),
                    ];
                })->all(),
            ];
        })->all();

        return [
            'course' => [
                'id' => $this->id,
                'slug' => $this->slug,
                'title' => $live->title,
                'cover_url' => $live->coverUrl(),
                'professor' => ['slug' => $this->professor->slug, 'display_name' => $this->professor->display_name],
                'updated_at' => $live->reviewed_at?->toIso8601String(),
            ],
            'progress_pct' => $this->enrollment->progress_pct,
            'completed_at' => $this->enrollment->completed_at?->toIso8601String(),
            'continue_lesson_uid' => $this->enrollment->last_lesson_uid ?? $next,
            'next_lesson_uid' => $next,
            'sections' => $sections,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function quizFacts(Lesson $lesson): array
    {
        $quiz = $lesson->quiz;
        $attempts = $this->attempts->where('lesson_uid', $lesson->uid);
        $submitted = $attempts->whereNotNull('submitted_at');

        return [
            'question_count' => $quiz->questions->count(),
            'pass_mark_pct' => $quiz->pass_mark_pct,
            'time_limit_seconds' => $quiz->time_limit_seconds,
            'max_attempts' => $quiz->max_attempts,
            'attempts_used' => $attempts->count(),
            'best_score_pct' => $submitted->max('score_pct'),
            'passed' => $submitted->contains('passed', true),
        ];
    }
}
