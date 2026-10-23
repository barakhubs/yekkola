<?php

declare(strict_types=1);

namespace App\Domain\Learning\Actions;

use App\Domain\Learning\Models\Review;
use Illuminate\Support\Facades\DB;

/** Removes a review (its author, or account deletion) and its effect on the ratings. */
final class DeleteReview
{
    public function __construct(private readonly RefreshRatings $ratings) {}

    public function handle(Review $review): void
    {
        DB::transaction(function () use ($review): void {
            $this->ratings->lock($review->course_id);
            Review::query()->whereKey($review->id)->delete();
            $this->ratings->handle($review->course_id);
        });
    }
}
