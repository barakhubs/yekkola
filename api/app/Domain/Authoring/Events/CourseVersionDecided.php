<?php

declare(strict_types=1);

namespace App\Domain\Authoring\Events;

use App\Domain\Authoring\Models\CourseVersion;

/**
 * Approved or rejected — search indexing (PRD-04) and notifications (PRD-10) listen for this.
 */
final readonly class CourseVersionDecided
{
    public function __construct(public CourseVersion $version) {}
}
