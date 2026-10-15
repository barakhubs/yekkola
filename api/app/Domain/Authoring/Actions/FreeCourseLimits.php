<?php

declare(strict_types=1);

namespace App\Domain\Authoring\Actions;

use App\Domain\Authoring\Enums\CourseStatus;
use App\Domain\Authoring\Exceptions\AuthoringException;
use App\Domain\Authoring\Models\Course;
use App\Domain\Platform\Settings\CatalogSettings;

/**
 * Free-course limits per professor (PRD-03 FR-18, platform settings): max number of free courses and max
 * hours of free media. Blank settings mean unlimited. Checked when making a course free and on submit.
 */
final class FreeCourseLimits
{
    public function __construct(private readonly CatalogSettings $settings) {}

    public function assertCanMakeFree(Course $course, int $extraSeconds = 0): void
    {
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
        if ($hoursLimit !== null && (int) (clone $others)->sum('total_duration_seconds') + $extraSeconds > $hoursLimit * 3_600) {
            throw AuthoringException::freeCourseLimit($courseLimit);
        }
    }
}
