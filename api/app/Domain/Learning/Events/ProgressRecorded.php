<?php

declare(strict_types=1);

namespace App\Domain\Learning\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A student's course progress was recomputed (sync, quiz pass, new live version, re-grant). Certificates
 * listen and apply the admin's completion rule (PRD-06 FR-10, LearningSettings).
 */
final readonly class ProgressRecorded implements ShouldDispatchAfterCommit
{
    public function __construct(public string $enrollmentId) {}
}
