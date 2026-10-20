<?php

declare(strict_types=1);

namespace App\Domain\Learning\Listeners;

use App\Domain\Identity\Events\UserAnonymizing;
use App\Domain\Learning\Models\LessonProgress;
use App\Domain\Learning\Models\QuizAttempt;
use App\Domain\Learning\Models\WishlistItem;

/**
 * Erases a deleted account's learning data (PRD-01 FR-11): the wishlist, and the device links on progress
 * and quiz attempts. Enrollments, progress, and attempts stay as records of an anonymised account
 * (purchases, professor statistics).
 */
final class EraseLearningData
{
    public function handle(UserAnonymizing $event): void
    {
        WishlistItem::query()->where('user_id', $event->user->id)->delete();
        LessonProgress::query()->where('user_id', $event->user->id)->update(['device_id' => null]);
        QuizAttempt::query()->where('user_id', $event->user->id)->update(['device_id' => null]);
    }
}
