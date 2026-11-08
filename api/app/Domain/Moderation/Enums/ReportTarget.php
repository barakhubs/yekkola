<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Enums;

/** What can be reported (PRD-06 FR-14, PRD-09 FR-10). */
enum ReportTarget: string
{
    case Course = 'course';
    case Review = 'review';
    case Professor = 'professor';
    /** A lesson question (PRD-06 FR-13). */
    case Thread = 'thread';
    /** A reply to a lesson question. */
    case Reply = 'reply';
}
