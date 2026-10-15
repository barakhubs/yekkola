<?php

declare(strict_types=1);

namespace App\Domain\Professors\Enums;

enum ProfessorStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
