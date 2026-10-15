<?php

declare(strict_types=1);

namespace App\Domain\Authoring\Actions;

use App\Domain\Authoring\Enums\CourseStatus;
use App\Domain\Authoring\Exceptions\AuthoringException;
use App\Domain\Authoring\Models\Course;
use App\Domain\Authoring\Models\Lesson;
use App\Domain\Platform\Settings\CatalogSettings;
use App\Domain\Professors\Models\ProfessorProfile;

/**
 * Free-course limits per professor (PRD-03 FR-18, platform settings): max number of free courses and max
 * hours of free media. Blank settings mean unlimited. Checked when making a course free and on submit.
 * Call inside a transaction: the professor row is locked so concurrent requests can't both pass.
 */
final class FreeCourseLimits
{
    public function __construct(private readonly CatalogSettings $settings) {}

    public function assertCanMakeFree(Course $course, ?int $courseSeconds = null): void
    {
        ProfessorProfile::query()->whereKey($course->professor_profile_id)->lockForUpdate()->first();
        $courseSeconds ??= $this->mediaSeconds($course);

        $others = Course::query()
            ->where('professor_profile_id', $course->professor_profile_id)
            ->where('is_free', true)
            ->where('status', '!=', CourseStatus::Archived)
            ->whereKeyNot($course->id);

        $courseLimit = $this->settings->free_course_limit;
        if ($courseLimit !== null && (clone $others)->count() >= $courseLimit) {
            throw AuthoringException::freeCourseLimit($courseLimit);
        }

        $hoursLimit = $this->settings->free_media_hours_limit;
        if ($hoursLimit !== null && (int) (clone $others)->sum('total_duration_seconds') + $courseSeconds > $hoursLimit * 3_600) {
            throw AuthoringException::freeHoursLimit($hoursLimit);
        }
    }

    /** Live duration, or the open draft's for a course never published. */
    private function mediaSeconds(Course $course): int
    {
        if ($course->live_version_id !== null) {
            return $course->total_duration_seconds;
        }

        return $course->draft_version_id === null
            ? 0
            : (int) Lesson::query()->where('course_version_id', $course->draft_version_id)->sum('duration_seconds');
    }
}
