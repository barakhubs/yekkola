<?php

declare(strict_types=1);

namespace App\Domain\Professors\Events;

use App\Domain\Professors\Models\ProfessorApplication;

/**
 * Approved, rejected, or info requested — notifications (PRD-10) listen for this.
 */
final readonly class ApplicationDecided
{
    public function __construct(public ProfessorApplication $application) {}
}
