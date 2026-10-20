<?php

declare(strict_types=1);

namespace App\Domain\Learning\Actions;

use App\Domain\Learning\Enums\EnrollmentSource;
use App\Domain\Learning\Enums\EnrollmentStatus;
use App\Domain\Learning\Models\Enrollment;

/**
 * Gives a user access to a course (PRD-06). One enrollment per user and course: an active one is left as
 * it is (returns false); a revoked one is reactivated with the new source. Call inside the caller's
 * transaction (purchases: only from the payment-success handler, PRD-05 BR-01).
 */
final class GrantEnrollment
{
    public function __construct(private readonly RecordCourseProgress $progress) {}

    /**
     * @return bool Whether access was newly granted.
     */
    public function handle(string $userId, string $courseId, EnrollmentSource $source, ?string $orderItemId = null): bool
    {
        $enrollment = Enrollment::query()
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->lockForUpdate()
            ->first();

        if ($enrollment?->isActive()) {
            return false;
        }

        $attributes = [
            'source' => $source,
            'order_item_id' => $orderItemId,
            'status' => EnrollmentStatus::Active,
            'revoked_reason' => null,
            'revoked_at' => null,
            'enrolled_at' => now(),
        ];

        if ($enrollment === null) {
            $created = Enrollment::query()->createOrFirst(['user_id' => $userId, 'course_id' => $courseId], $attributes);
            if ($created->wasRecentlyCreated) {
                $this->progress->handle($created); // progress from an earlier enrollment still counts
            }

            return $created->wasRecentlyCreated;
        }

        // A new grant starts a new completion: the student completes (and is certified) again.
        $enrollment->forceFill([...$attributes, 'completed_at' => null])->save();
        $this->progress->handle($enrollment);

        return true;
    }
}
