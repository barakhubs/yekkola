<?php

declare(strict_types=1);

namespace App\Domain\Learning\Jobs;

use App\Domain\Learning\Actions\RefreshRatings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes ratings after something outside a review changed what counts (access revoked or granted
 * again, ban) — queued after commit so payment and refund transactions never take the rating locks.
 */
final class RefreshCourseRatings implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public readonly string $courseId) {}

    public function handle(RefreshRatings $ratings): void
    {
        DB::transaction(function () use ($ratings): void {
            $ratings->lock($this->courseId);
            $ratings->handle($this->courseId);
        });
    }
}
