<?php

declare(strict_types=1);

namespace App\Domain\Learning\Actions;

use App\Domain\Authoring\Models\Course;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Enums\EnrollmentSource;
use App\Domain\Learning\Exceptions\LearningException;
use App\Domain\Learning\Models\Enrollment;
use Illuminate\Database\ConnectionInterface;

/**
 * One-tap enrollment in a free course (PRD-05 FR-01): no order, payment, or ledger entry. Idempotent.
 * A revoked enrollment is not restored this way (revocations are deliberate: takedown, ban).
 */
final class EnrollInFreeCourse
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly GrantEnrollment $grant,
    ) {}

    public function handle(User $user, string $courseId): Enrollment
    {
        $course = Course::query()->listed()->findOrFail($courseId);

        if (! $course->is_free) {
            throw LearningException::notFree();
        }

        return $this->db->transaction(function () use ($user, $course): Enrollment {
            $existing = Enrollment::query()->where('user_id', $user->id)->where('course_id', $course->id)->lockForUpdate()->first();

            if ($existing !== null && ! $existing->isActive()) {
                throw LearningException::revoked();
            }

            $this->grant->handle($user->id, $course->id, EnrollmentSource::Free);

            return Enrollment::query()->where('user_id', $user->id)->where('course_id', $course->id)->firstOrFail();
        });
    }
}
