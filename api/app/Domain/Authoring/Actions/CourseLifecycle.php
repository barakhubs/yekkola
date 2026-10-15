<?php

declare(strict_types=1);

namespace App\Domain\Authoring\Actions;

use App\Domain\Authoring\Enums\CourseStatus;
use App\Domain\Authoring\Exceptions\AuthoringException;
use App\Domain\Authoring\Models\Course;
use Illuminate\Database\ConnectionInterface;

/**
 * Course visibility (PRD-03 FR-16/17): unpublish and republish (no review needed — the live version is
 * unchanged), archive, and delete (only courses never published; published ones are archived instead,
 * so enrolled students keep access).
 */
final class CourseLifecycle
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function unpublish(Course $course): Course
    {
        return $this->move($course, [CourseStatus::Published], CourseStatus::Unpublished);
    }

    public function republish(Course $course): Course
    {
        return $this->move($course, [CourseStatus::Unpublished, CourseStatus::Archived], CourseStatus::Published);
    }

    public function archive(Course $course): Course
    {
        return $this->move($course, [CourseStatus::Published, CourseStatus::Unpublished], CourseStatus::Archived);
    }

    public function delete(Course $course): void
    {
        $this->db->transaction(function () use ($course): void {
            $locked = Course::query()->whereKey($course->id)->lockForUpdate()->firstOrFail();

            // TODO(1.8): also require zero enrollments once enrollments exist.
            if ($locked->wasEverPublished()) {
                throw AuthoringException::cannotDeletePublished();
            }

            if ($locked->status === CourseStatus::InReview) {
                throw AuthoringException::invalidTransition(); // withdraw first, so the review queue never points at a deleted course
            }

            $locked->delete();
        });
    }

    /**
     * @param  list<CourseStatus>  $from
     */
    private function move(Course $course, array $from, CourseStatus $to): Course
    {
        return $this->db->transaction(function () use ($course, $from, $to): Course {
            $locked = Course::query()->whereKey($course->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $from, true) || ! $locked->wasEverPublished()) {
                throw AuthoringException::invalidTransition();
            }

            $locked->forceFill(['status' => $to])->save();

            return $locked;
        });
    }
}
