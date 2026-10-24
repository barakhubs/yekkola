<?php

declare(strict_types=1);

namespace App\Domain\Learning;

use App\Domain\Authoring\Models\Course;
use App\Domain\Authoring\Models\Lesson;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Exceptions\LearningException;
use App\Domain\Learning\Models\Enrollment;

/**
 * The only content access check (PRD-06 BR-01): an active enrollment, or a preview lesson. Lessons are
 * found by uid in the course's live version (students always see the live version).
 */
final class CourseAccess
{
    public function enrollment(User $user, string $courseId): Enrollment
    {
        return $this->activeEnrollment($user, $courseId) ?? throw LearningException::enrollmentRequired();
    }

    public function activeEnrollment(User $user, string $courseId): ?Enrollment
    {
        return Enrollment::query()->active()->where('user_id', $user->id)->where('course_id', $courseId)->first();
    }

    /**
     * @return array{0: Lesson, 1: Course}
     */
    public function lesson(User $user, string $lessonUid, bool $allowPreview = false): array
    {
        [$lesson, $course] = $this->liveLesson($lessonUid);

        if (! ($allowPreview && $lesson->is_preview)) {
            $this->enrollment($user, $course->id);
        }

        return [$lesson, $course];
    }

    /**
     * A preview lesson is open to anyone only while its course is listed (published, active professor):
     * a takedown or suspension stops previews too.
     */
    public function previewAllowed(Lesson $lesson, Course $course): bool
    {
        return $lesson->is_preview && $course->loadMissing('professor.user')->isListed();
    }

    /**
     * The lesson with this uid in its course's live version — no access check.
     *
     * @return array{0: Lesson, 1: Course}
     */
    public function liveLesson(string $lessonUid): array
    {
        $lesson = Lesson::query()->where('uid', $lessonUid)
            ->whereHas('version', fn ($q) => $q->whereIn('id', Course::query()->whereNotNull('live_version_id')->select('live_version_id')))
            ->with('version')
            ->first() ?? throw LearningException::lessonNotFound();

        $course = Course::query()->where('live_version_id', $lesson->course_version_id)->firstOrFail();

        return [$lesson, $course];
    }
}
