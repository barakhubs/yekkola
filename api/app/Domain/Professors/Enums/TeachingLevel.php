<?php

declare(strict_types=1);

namespace App\Domain\Professors\Enums;

/** Levels a professor teaches (application form, profile). */
enum TeachingLevel: string
{
    case Secondary = 'secondary';
    case Exetat = 'exetat';
    case University = 'university';
    case Professional = 'professional';
}
