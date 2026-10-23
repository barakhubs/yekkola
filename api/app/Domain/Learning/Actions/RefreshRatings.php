<?php

declare(strict_types=1);

namespace App\Domain\Learning\Actions;

use App\Domain\Authoring\Models\Course;
use App\Domain\Learning\Models\Review;
use App\Domain\Professors\Models\ProfessorProfile;

/**
 * Recomputes a course's rating and its professor's from published reviews (visible, author not banned —
 * PRD-06 BR-03). Derived from the reviews table every time, so it never drifts. Call inside the caller's
 * transaction with the course locked, so concurrent reviews of one course apply one after the other.
 */
final class RefreshRatings
{
    public function handle(string $courseId): void
    {
        $course = Course::query()->withTrashed()->findOrFail($courseId);
        [$avg, $count] = $this->aggregate(Review::query()->published()->where('reviews.course_id', $courseId));
        $course->forceFill(['rating_avg' => $avg, 'rating_count' => $count])->saveQuietly();

        [$avg, $count] = $this->aggregate(Review::query()->published()
            ->whereIn('reviews.course_id', Course::query()->withTrashed()->where('professor_profile_id', $course->professor_profile_id)->select('id')));
        ProfessorProfile::query()->whereKey($course->professor_profile_id)->update(['rating_avg' => $avg, 'rating_count' => $count]);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Review>  $reviews
     * @return array{0: float, 1: int}
     */
    private function aggregate($reviews): array
    {
        $row = $reviews->toBase()->selectRaw('coalesce(avg(rating), 0) as avg, count(*) as count')->first();

        return [round((float) ($row->avg ?? 0), 2), (int) ($row->count ?? 0)];
    }
}
