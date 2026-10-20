<?php

declare(strict_types=1);

namespace App\Domain\Learning\Jobs;

use App\Domain\Learning\Actions\RecordCourseProgress;
use App\Domain\Learning\Models\Enrollment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A new version went live: progress counts only its lessons (PRD-06 BR-02), so every active enrollment's
 * progress is recomputed — removed lessons stop counting, and a student may now be complete.
 */
final class RecomputeCourseProgress implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public readonly string $courseId) {}

    public function handle(ConnectionInterface $db, RecordCourseProgress $progress): void
    {
        Enrollment::query()->active()->where('course_id', $this->courseId)->select('id')->lazyById(200)
            ->each(fn (Enrollment $e) => $db->transaction(function () use ($e, $progress): void {
                $locked = Enrollment::query()->active()->whereKey($e->id)->lockForUpdate()->first();
                if ($locked !== null) {
                    $progress->handle($locked);
                }
            }));
    }
}
