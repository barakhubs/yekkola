<?php

declare(strict_types=1);

namespace App\Domain\Authoring\Enums;

/** Who a course is for (PRD-03 FR-09); a catalogue filter (PRD-04 FR-02). */
enum Audience: string
{
    case Exetat = 'exetat';
    case Secondaire = 'secondaire';
    case Universite = 'universite';
    case Professionnel = 'professionnel';
}
