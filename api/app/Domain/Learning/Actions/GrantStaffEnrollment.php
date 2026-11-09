<?php

declare(strict_types=1);

namespace App\Domain\Learning\Actions;

use App\Domain\Authoring\Exceptions\AuthoringException;
use App\Domain\Authoring\Models\Course;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Enums\EnrollmentSource;
use App\Domain\Learning\Exceptions\LearningException;
use App\Domain\Platform\Actions\RecordAudit;
use Illuminate\Database\ConnectionInterface;

/**
 * Staff give an account access to a course (PRD-09 FR-04), e.g. after a payment problem settled outside the
 * platform or a partner arrangement. No order, payment or ledger entry; source `admin`; the reason is audited.
 * The course must have a live version and not be taken down; banned accounts get nothing.
 */
final class GrantStaffEnrollment
{
    public function __construct(
        private readonly GrantEnrollment $grant,
        private readonly RecordAudit $recordAudit,
        private readonly ConnectionInterface $db,
    ) {}

    public function handle(User $user, string $courseId, User $staff, string $reason): void
    {
        if ($user->isBanned()) {
            throw LearningException::accountBanned();
        }

        $this->db->transaction(function () use ($user, $courseId, $staff, $reason): void {
            $course = Course::query()->whereKey($courseId)->lockForUpdate()->firstOrFail();
            if ($course->live_version_id === null) {
                throw AuthoringException::notPublished();
            }
            if ($course->taken_down_at !== null) {
                throw LearningException::courseTakenDown();
            }

            if (! $this->grant->handle($user->id, $course->id, EnrollmentSource::Admin)) {
                throw LearningException::alreadyEnrolled();
            }

            $this->recordAudit->handle('enrollment.granted', $user, ['course_id' => $course->id, 'reason' => $reason], causer: $staff);
        });
    }
}
