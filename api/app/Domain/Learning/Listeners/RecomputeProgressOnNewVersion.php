<?php

declare(strict_types=1);

namespace App\Domain\Learning\Listeners;

use App\Domain\Authoring\Enums\VersionState;
use App\Domain\Authoring\Events\CourseVersionDecided;
use App\Domain\Learning\Jobs\RecomputeCourseProgress;

/** When a version is approved (it is now live), queue the progress recomputation for its course. */
final class RecomputeProgressOnNewVersion
{
    public function handle(CourseVersionDecided $event): void
    {
        if ($event->version->state === VersionState::Approved) {
            RecomputeCourseProgress::dispatch($event->version->course_id)->afterCommit();
        }
    }
}
