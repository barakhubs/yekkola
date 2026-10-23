<?php

declare(strict_types=1);

namespace App\Domain\Learning\Actions;

use App\Domain\Authoring\Models\Course;
use App\Domain\Learning\Models\Review;
use App\Domain\Professors\Models\ProfessorProfile;

/**
 * Recomputes a course's rating and its professor's from published reviews (visible, author not banned and
 * still enrolled — PRD-06 FR-12, BR-03). Derived from the reviews table every time. Call inside the
 * caller's transaction after lock(), so concurrent reviews of one professor's courses apply in turn.
 */
final class RefreshRatings
{
    /** Locks the professor, then the course (always in this order), and returns the course. */
    public function lock(string $courseId): Course
    {
        $professorId = Course::query()->withTrashed()->whereKey($courseId)->value('professor_profile_id');
        ProfessorProfile::query()->whereKey($professorId)->lockForUpdate()->first();

        return Course::query()->withTrashed()->with('professor')->lockForUpdate()->findOrFail($courseId);
    }

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
