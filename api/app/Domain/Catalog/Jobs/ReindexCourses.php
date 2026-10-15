<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Jobs;

use App\Domain\Authoring\Models\Course;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Re-syncs the search index for every course of a professor or a category after something the index
 * depends on changed outside the course row (professor name or status, account ban, category name).
 * Courses that are no longer listed are removed from the index.
 */
final class ReindexCourses implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public readonly string $column, // professor_profile_id | category_id
        public readonly string $value,
    ) {}

    public function handle(): void
    {
        Course::query()
            ->where($this->column, $this->value)
            ->with('liveVersion', 'professor.user', 'category.parent')
            ->chunkById(100, function (Collection $courses): void {
                [$listed, $unlisted] = $courses->partition(fn (Course $c) => $c->shouldBeSearchable());
                $model = new Course;

                if ($listed->isNotEmpty()) {
                    $model->queueMakeSearchable($listed);
                }
                if ($unlisted->isNotEmpty()) {
                    $model->queueRemoveFromSearch($unlisted);
                }
            });
    }
}
