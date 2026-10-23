<?php

declare(strict_types=1);

namespace App\Domain\Learning\Listeners;

use App\Domain\Identity\Events\UserAnonymizing;
use App\Domain\Learning\Actions\DeleteReview;
use App\Domain\Learning\Models\Certificate;
use App\Domain\Learning\Models\LessonProgress;
use App\Domain\Learning\Models\QuizAttempt;
use App\Domain\Learning\Models\Review;
use App\Domain\Learning\Models\WishlistItem;

/**
 * Erases a deleted account's learning data (PRD-01 FR-11): the wishlist, reviews (ratings recomputed),
 * certificates (they carry the student's name; their links stop verifying), and the device links on
 * progress and quiz attempts. Enrollments, progress, and attempts stay as records of an anonymised account
 * (purchases, professor statistics).
 */
final class EraseLearningData
{
    public function __construct(private readonly DeleteReview $deleteReview) {}

    public function handle(UserAnonymizing $event): void
    {
        WishlistItem::query()->where('user_id', $event->user->id)->delete();
        Review::query()->where('user_id', $event->user->id)->get()->each(fn (Review $review) => $this->deleteReview->handle($review));
        Certificate::query()->where('user_id', $event->user->id)->delete();
        LessonProgress::query()->where('user_id', $event->user->id)->update(['device_id' => null]);
        QuizAttempt::query()->where('user_id', $event->user->id)->update(['device_id' => null]);
    }
}
