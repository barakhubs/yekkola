<?php

declare(strict_types=1);

namespace App\Domain\Learning\Actions;

use App\Domain\Learning\Models\Review;

/** The professor's public reply to a review of their course (PRD-06 FR-12); replying again edits it, null removes it. */
final class ReplyToReview
{
    public function handle(Review $review, ?string $reply): Review
    {
        // Written without touching updated_at: a reply is not an edit of the student's review (edited_at).
        Review::query()->toBase()->where('id', $review->id)->update([
            'professor_reply' => filled($reply) ? $reply : null,
            'replied_at' => filled($reply) ? now() : null,
        ]);

        return $review->refresh();
    }
}
