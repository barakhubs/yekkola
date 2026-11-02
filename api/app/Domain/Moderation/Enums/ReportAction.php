<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Enums;

/**
 * How staff resolve a report (PRD-09 FR-10). `hide` hides a review or takes a course down; `suspend` suspends a
 * review's author account, or the professor behind a course or profile.
 */
enum ReportAction: string
{
    case Dismiss = 'dismiss';
    case Hide = 'hide';
    case Warn = 'warn';
    case Suspend = 'suspend';
}
