<?php

declare(strict_types=1);

namespace App\Domain\Authoring\Enums;

/**
 * Quiz question kinds (PRD-03 FR-07).
 */
enum QuestionType: string
{
    /** Exactly one correct option. */
    case Single = 'single';
    /** One or more correct options. */
    case Multiple = 'multiple';
    /** Two options, one correct. */
    case TrueFalse = 'true_false';
}
