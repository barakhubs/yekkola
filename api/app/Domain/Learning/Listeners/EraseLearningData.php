<?php

declare(strict_types=1);

namespace App\Domain\Learning\Listeners;

use App\Domain\Identity\Events\UserAnonymizing;
use App\Domain\Learning\Models\WishlistItem;

/**
 * Erases a deleted account's learning data (PRD-01 FR-11). For now the wishlist; enrollments, progress,
 * and certificates are handled when they arrive (they are records the platform must keep, anonymised).
 */
final class EraseLearningData
{
    public function handle(UserAnonymizing $event): void
    {
        WishlistItem::query()->where('user_id', $event->user->id)->delete();
    }
}
