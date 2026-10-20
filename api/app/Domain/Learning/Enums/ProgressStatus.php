<?php

declare(strict_types=1);

namespace App\Domain\Learning\Enums;

/** A lesson's progress for a student; completion never regresses (PRD-06 FR-05). */
enum ProgressStatus: string
{
    case InProgress = 'in_progress';
    case Completed = 'completed';
}
