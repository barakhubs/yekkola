<?php

declare(strict_types=1);

namespace App\Domain\Learning\Enums;

/** Hidden reviews (moderation, PRD-09) stay with their author but leave the public list and the rating. */
enum ReviewStatus: string
{
    case Visible = 'visible';
    case Hidden = 'hidden';
}
