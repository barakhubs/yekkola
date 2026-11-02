<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Enums;

/** What can be reported (PRD-06 FR-14, PRD-09 FR-10). Lesson Q&A threads and replies join with Q&A (FR-13). */
enum ReportTarget: string
{
    case Course = 'course';
    case Review = 'review';
    case Professor = 'professor';
}
