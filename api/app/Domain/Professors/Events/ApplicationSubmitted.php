<?php

declare(strict_types=1);

namespace App\Domain\Professors\Events;

use App\Domain\Professors\Models\ProfessorApplication;

final readonly class ApplicationSubmitted
{
    public function __construct(public ProfessorApplication $application) {}
}
