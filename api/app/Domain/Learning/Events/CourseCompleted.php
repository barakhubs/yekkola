<?php

declare(strict_types=1);

namespace App\Domain\Learning\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** A student completed every lesson of a course (certificates listen, PRD-06 FR-10). */
final readonly class CourseCompleted implements ShouldDispatchAfterCommit
{
    public function __construct(public string $enrollmentId) {}
}
