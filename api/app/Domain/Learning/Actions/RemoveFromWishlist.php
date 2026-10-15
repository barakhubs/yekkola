<?php

declare(strict_types=1);

namespace App\Domain\Learning\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Learning\Models\WishlistItem;

/**
 * Removes a course from a student's wishlist (PRD-04 FR-08), whatever its status.
 */
final class RemoveFromWishlist
{
    public function handle(User $user, string $courseId): void
    {
        WishlistItem::query()->where('user_id', $user->id)->where('course_id', $courseId)->delete();
    }
}
