<?php

declare(strict_types=1);

namespace App\Domain\Authoring\Events;

use App\Domain\Authoring\Models\CourseVersion;

final readonly class CourseVersionSubmitted
{
    public function __construct(public CourseVersion $version) {}
}
