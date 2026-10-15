<?php

declare(strict_types=1);

namespace App\Domain\Learning\Actions;

use App\Domain\Authoring\Models\Course;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Models\WishlistItem;

/**
 * Saves a course to a student's wishlist (PRD-04 FR-08). Idempotent; only listed courses.
 */
final class AddToWishlist
{
    public function handle(User $user, string $courseId): void
    {
        $course = Course::query()->listed()->findOrFail($courseId);

        WishlistItem::query()->insertOrIgnore([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'created_at' => now(),
        ]);
    }
}
