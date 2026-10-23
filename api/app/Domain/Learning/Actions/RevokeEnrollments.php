<?php

declare(strict_types=1);

namespace App\Domain\Learning\Actions;

use App\Domain\Learning\Enums\EnrollmentStatus;
use App\Domain\Learning\Jobs\RefreshCourseRatings;
use App\Domain\Learning\Models\Certificate;
use App\Domain\Learning\Models\Enrollment;
use App\Domain\Learning\Models\Review;

/**
 * Removes access granted by the given order items (payment reversal, refunds). Streaming stops at once;
 * offline copies are deleted at the device's next sync (PRD-07). Certificates earned under those grants
 * are revoked; verification shows them as revoked (PRD-06 FR-11), and reviews stop counting (FR-12). Call
 * inside the caller's transaction.
 */
final class RevokeEnrollments
{
    /**
     * @param  list<string>  $orderItemIds
     * @return int Enrollments revoked.
     */
    public function handle(array $orderItemIds, string $reason): int
    {
        $revoked = Enrollment::query()
            ->whereIn('order_item_id', $orderItemIds)
            ->where('status', EnrollmentStatus::Active)
            ->lockForUpdate()
            ->get(['id', 'user_id', 'course_id']);
        $ids = $revoked->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        Certificate::query()->whereIn('enrollment_id', $ids)->valid()
            ->update(['revoked_at' => now(), 'revoked_reason' => $reason, 'updated_at' => now()]);

        // Their reviews stop counting (Review::published); ratings recomputed after commit.
        $revoked->each(function (Enrollment $e): void {
            if (Review::query()->where('user_id', $e->user_id)->where('course_id', $e->course_id)->exists()) {
                RefreshCourseRatings::dispatch($e->course_id)->afterCommit();
            }
        });

        return Enrollment::query()
            ->whereIn('id', $ids)
            ->update(['status' => EnrollmentStatus::Revoked, 'revoked_reason' => $reason, 'revoked_at' => now(), 'updated_at' => now()]);
    }
}
