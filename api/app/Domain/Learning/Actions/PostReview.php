<?php

declare(strict_types=1);

namespace App\Domain\Learning\Actions;

use App\Domain\Authoring\Models\Course;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\CourseAccess;
use App\Domain\Learning\Exceptions\LearningException;
use App\Domain\Learning\Models\Review;
use App\Domain\Platform\Settings\LearningSettings;
use Illuminate\Support\Facades\DB;

/**
 * Posts or edits a student's review (PRD-06 FR-12): enrolled students past the progress threshold only,
 * never on their own course (BR-04), one per course. A hidden review stays hidden when edited.
 */
final class PostReview
{
    public function __construct(
        private readonly CourseAccess $access,
        private readonly LearningSettings $settings,
        private readonly RefreshRatings $ratings,
    ) {}

    /**
     * @param  bool  $creating  POST creates (refused if one exists), PATCH edits (404 if none).
     */
    public function handle(User $user, string $courseId, int $rating, ?string $body, bool $creating): Review
    {
        return DB::transaction(function () use ($user, $courseId, $rating, $body, $creating): Review {
            $course = Course::query()->with('professor')->lockForUpdate()->findOrFail($courseId);
            if ($course->professor->user_id === $user->id) {
                throw LearningException::ownCourseReview();
            }

            $enrollment = $this->access->enrollment($user, $course->id);
            $min = $this->settings->review_min_progress_pct;
            if ($enrollment->progress_pct < $min) {
                throw LearningException::reviewProgressTooLow($min);
            }

            $mine = Review::query()->where('user_id', $user->id)->where('course_id', $course->id);
            if ($creating && $mine->exists()) {
                throw LearningException::reviewExists();
            }

            $review = $creating ? new Review(['user_id' => $user->id, 'course_id' => $course->id]) : $mine->firstOrFail();
            $review->fill(['rating' => $rating, 'body' => filled($body) ? $body : null])->save();
            $this->ratings->handle($course->id);

            return $review;
        });
    }
}
