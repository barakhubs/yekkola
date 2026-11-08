<?php

declare(strict_types=1);

namespace App\Domain\Learning\Enums;

/** A lesson question is answered once the course's professor replies (PRD-06 FR-13). */
enum ThreadStatus: string
{
    case Open = 'open';
    case Answered = 'answered';
}
