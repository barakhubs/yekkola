<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Enums;

enum ReportReason: string
{
    case Spam = 'spam';
    /** Insults, harassment, hate. */
    case Abusive = 'abusive';
    /** Sexual, violent or otherwise unsuitable content. */
    case Inappropriate = 'inappropriate';
    /** False information, or a course that isn't what it claims. */
    case Misleading = 'misleading';
    /** Someone else's work published without permission. */
    case Copyright = 'copyright';
    case Fraud = 'fraud';
    case Other = 'other';
}
