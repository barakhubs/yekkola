<?php

declare(strict_types=1);

namespace App\Domain\Learning\Actions;

use App\Domain\Learning\Enums\EnrollmentStatus;
use App\Domain\Learning\Models\Enrollment;

/**
 * Removes access granted by the given order items (payment reversal, refunds). Streaming stops at once;
 * offline copies are deleted at the device's next sync (PRD-07). Call inside the caller's transaction.
 */
final class RevokeEnrollments
{
    /**
     * @param  list<string>  $orderItemIds
     * @return int Enrollments revoked.
     */
    public function handle(array $orderItemIds, string $reason): int
    {
        return Enrollment::query()
            ->whereIn('order_item_id', $orderItemIds)
            ->where('status', EnrollmentStatus::Active)
            ->update(['status' => EnrollmentStatus::Revoked, 'revoked_reason' => $reason, 'revoked_at' => now(), 'updated_at' => now()]);
    }
}
