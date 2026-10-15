<?php

declare(strict_types=1);

namespace App\Domain\Professors\Enums;

enum ApplicationStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case NeedsInfo = 'needs_info';
    case Approved = 'approved';
    case Rejected = 'rejected';
    /** "needs info" left unanswered for 30 days. */
    case Expired = 'expired';

    /** Open applications block starting another one (FR-02). */
    public function isOpen(): bool
    {
        return in_array($this, [self::Draft, self::Submitted, self::UnderReview, self::NeedsInfo], true);
    }

    /** The applicant may edit the application. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::NeedsInfo], true);
    }

    /** Staff may decide on it. */
    public function isDecidable(): bool
    {
        return in_array($this, [self::Submitted, self::UnderReview], true);
    }
}
