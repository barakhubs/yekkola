<?php

declare(strict_types=1);

namespace App\Domain\Learning\Actions;

use App\Domain\Authoring\Models\Course;
use App\Domain\Authoring\Models\Lesson;
use App\Domain\Learning\Enums\ProgressStatus;
use App\Domain\Learning\Events\CourseCompleted;
use App\Domain\Learning\Events\ProgressRecorded;
use App\Domain\Learning\Models\Enrollment;
use App\Domain\Learning\Models\LessonProgress;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Course progress = completed lessons of the live version / its lessons (PRD-06 FR-09, BR-02: lessons
 * removed in a new version no longer count). Updates the enrollment and announces every change
 * (ProgressRecorded — certificates apply the admin's completion rule) and, once per grant, full completion
 * (CourseCompleted). Call inside the caller's transaction, with the enrollment locked.
 */
final class RecordCourseProgress
{
    public function __construct(private readonly Dispatcher $events) {}

    public function handle(Enrollment $enrollment, ?string $lastLessonUid = null): Enrollment
    {
        $course = Course::query()->findOrFail($enrollment->course_id);
        $uids = Lesson::query()->where('course_version_id', $course->live_version_id)->pluck('uid');

        $completed = $uids->isEmpty() ? 0 : LessonProgress::query()
            ->where('user_id', $enrollment->user_id)
            ->whereIn('lesson_uid', $uids)
            ->where('status', ProgressStatus::Completed)
            ->count();

        $pct = $uids->isEmpty() ? 0 : intdiv($completed * 100, $uids->count());
        $wasComplete = $enrollment->completed_at !== null;

        $enrollment->forceFill([
            'progress_pct' => $pct,
            'last_lesson_uid' => $lastLessonUid ?? $enrollment->last_lesson_uid,
            'last_activity_at' => now(),
            'completed_at' => $enrollment->completed_at ?? ($pct === 100 ? now() : null),
        ])->save();

        $this->events->dispatch(new ProgressRecorded($enrollment->id));
        if (! $wasComplete && $enrollment->completed_at !== null) {
            $this->events->dispatch(new CourseCompleted($enrollment->id));
        }

        return $enrollment;
    }
}
