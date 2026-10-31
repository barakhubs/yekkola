<?php

declare(strict_types=1);

namespace App\Domain\Authoring\Actions;

use App\Domain\Authoring\Enums\CourseStatus;
use App\Domain\Authoring\Exceptions\AuthoringException;
use App\Domain\Authoring\Models\Course;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Actions\RecordAudit;
use Illuminate\Database\ConnectionInterface;

/**
 * Staff decisions on a course's availability (PRD-09 FR-08), each with a reason in the audit log:
 * - unpublish: off the catalogue for new students; enrolled students keep their access;
 * - republish: back on the catalogue;
 * - take down: off the catalogue and closed to everyone, enrolled students included — no new playback or document
 *   tokens from now on (PRD-07 FR-15); downloads go at the devices' next sync;
 * - restore: a taken-down course is published again.
 * Saving the course refreshes the public pages and the search index (RevalidateWebOnChange, SyncCourseIndex).
 */
final class ChangeCourseAvailability
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly RecordAudit $recordAudit,
    ) {}

    public function unpublish(Course $course, User $staff, string $reason): Course
    {
        return $this->change($course, $staff, 'course.unpublished', $reason, function (Course $c): void {
            if ($c->status !== CourseStatus::Published || $c->taken_down_at !== null) {
                throw AuthoringException::availabilityTransition();
            }
            $c->forceFill(['status' => CourseStatus::Unpublished, 'featured_position' => null]);
        });
    }

    public function republish(Course $course, User $staff, string $reason): Course
    {
        return $this->change($course, $staff, 'course.republished', $reason, function (Course $c): void {
            if ($c->status !== CourseStatus::Unpublished || $c->taken_down_at !== null || $c->live_version_id === null) {
                throw AuthoringException::availabilityTransition();
            }
            $c->forceFill(['status' => CourseStatus::Published]);
        });
    }

    public function takeDown(Course $course, User $staff, string $reason): Course
    {
        return $this->change($course, $staff, 'course.taken_down', $reason, function (Course $c) use ($reason): void {
            if ($c->taken_down_at !== null || $c->live_version_id === null) {
                throw AuthoringException::availabilityTransition();
            }
            $c->forceFill([
                'status' => CourseStatus::Unpublished,
                'featured_position' => null,
                'taken_down_at' => now(),
                'takedown_reason' => $reason,
            ]);
        });
    }

    public function restore(Course $course, User $staff, string $reason): Course
    {
        return $this->change($course, $staff, 'course.restored', $reason, function (Course $c): void {
            if ($c->taken_down_at === null) {
                throw AuthoringException::availabilityTransition();
            }
            $c->forceFill(['status' => CourseStatus::Published, 'taken_down_at' => null, 'takedown_reason' => null]);
        });
    }

    /**
     * @param  callable(Course): void  $apply
     */
    private function change(Course $course, User $staff, string $event, string $reason, callable $apply): Course
    {
        return $this->db->transaction(function () use ($course, $staff, $event, $reason, $apply): Course {
            $course = Course::query()->whereKey($course->id)->lockForUpdate()->firstOrFail();
            $from = $course->status->value;
            $apply($course);
            $course->save();
            $this->recordAudit->handle($event, $course, ['reason' => $reason, 'from' => $from, 'to' => $course->status->value], causer: $staff);

            return $course;
        });
    }
}
