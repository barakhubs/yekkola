<?php

declare(strict_types=1);

namespace App\Domain\Learning\Listeners;

use App\Domain\Identity\Models\User;
use App\Domain\Learning\Jobs\RefreshCourseRatings;
use App\Domain\Learning\Models\Review;

/** Banning (or un-banning) a user hides (or restores) their reviews, so the ratings they fed change (PRD-06 BR-03). */
final class RefreshRatingsOnUserStatus
{
    public function handle(User $user): void
    {
        if (! $user->wasChanged('status')) {
            return;
        }

        Review::query()->where('user_id', $user->id)->pluck('course_id')
            ->each(fn (string $courseId) => RefreshCourseRatings::dispatch($courseId)->afterCommit());
    }
}
