<?php

declare(strict_types=1);

namespace App\Domain\Learning\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** A student completed every lesson of a course — once per grant (celebration, PRD-06 FR-16). Certificates use ProgressRecorded. */
final readonly class CourseCompleted implements ShouldDispatchAfterCommit
{
    public function __construct(public string $enrollmentId) {}
}
