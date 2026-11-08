<?php

declare(strict_types=1);

namespace App\Domain\Learning;

use App\Domain\Authoring\Models\Course;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Exceptions\LearningException;

/**
 * Who takes part in a course's lesson Q&A (PRD-06 FR-13): students with an active enrollment (not on a taken-down
 * course, as for the content) and the course's professor while their profile is active.
 */
final class QaAccess
{
    public function __construct(private readonly CourseAccess $access) {}

    public function assertParticipant(User $user, Course $course): void
    {
        if (! $this->isParticipant($user, $course)) {
            throw LearningException::qaAccessRequired();
        }
    }

    public function isParticipant(User $user, Course $course): bool
    {
        if (self::isProfessorOf($user, $course)) {
            return true;
        }

        return $this->access->activeEnrollment($user, $course->id) !== null;
    }

    public static function isProfessorOf(User $user, Course $course): bool
    {
        $professor = $course->loadMissing('professor')->professor;

        return $professor !== null && $professor->user_id === $user->id && $professor->isActive();
    }
}
