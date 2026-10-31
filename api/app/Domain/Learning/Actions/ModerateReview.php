<?php

declare(strict_types=1);

namespace App\Domain\Learning\Actions;

use App\Domain\Catalog\Jobs\RevalidateWebPages;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Enums\ReviewStatus;
use App\Domain\Learning\Exceptions\LearningException;
use App\Domain\Learning\Models\Review;
use App\Domain\Platform\Actions\RecordAudit;
use Illuminate\Database\ConnectionInterface;

/**
 * Staff hide a review that breaks the rules, or restore it (PRD-09 FR-10). A hidden review leaves the course page
 * and the course's and professor's ratings at once; the student still sees their own. Each decision is audited
 * with its reason.
 */
final class ModerateReview
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly RefreshRatings $ratings,
        private readonly RecordAudit $recordAudit,
    ) {}

    public function hide(Review $review, User $staff, string $reason): Review
    {
        return $this->change($review, $staff, 'review.hidden', $reason, ReviewStatus::Hidden);
    }

    public function restore(Review $review, User $staff, string $reason): Review
    {
        return $this->change($review, $staff, 'review.restored', $reason, ReviewStatus::Visible);
    }

    private function change(Review $review, User $staff, string $event, string $reason, ReviewStatus $to): Review
    {
        $review = $this->db->transaction(function () use ($review, $staff, $event, $reason, $to): Review {
            // Same lock order as every rating refresh: professor, then course, then the review.
            $course = $this->ratings->lock($review->course_id);
            $review = Review::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();
            if ($review->status === $to) {
                throw LearningException::reviewModerationTransition();
            }

            $review->forceFill($to === ReviewStatus::Hidden
                ? ['status' => $to, 'hidden_at' => now(), 'hidden_by' => $staff->id, 'hidden_reason' => $reason]
                : ['status' => $to, 'hidden_at' => null, 'hidden_by' => null, 'hidden_reason' => null])->saveQuietly();
            $this->ratings->handle($course->id);
            $this->recordAudit->handle($event, $review, ['reason' => $reason, 'course_id' => $course->id], causer: $staff);

            return $review;
        });

        // The course page shows reviews and the rating; the professor page shows the rating.
        $review->loadMissing('course.professor');
        RevalidateWebPages::dispatch(array_values(array_filter([
            'courses',
            $review->course !== null ? "course:{$review->course->slug}" : null,
            $review->course?->professor !== null ? "professor:{$review->course->professor->slug}" : null,
        ])))->afterCommit();

        return $review;
    }
}
