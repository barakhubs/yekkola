<?php

declare(strict_types=1);

namespace App\Domain\Authoring\Enums;

/**
 * Course difficulty shown in the catalogue.
 */
enum CourseLevel: string
{
    case Beginner = 'beginner';
    case Intermediate = 'intermediate';
    case Advanced = 'advanced';
    case All = 'all';
}
