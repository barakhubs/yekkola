<?php

declare(strict_types=1);

namespace App\Domain\Authoring\Enums;

/**
 * State of one course version (PRD-03 FR-11/12). Approved versions are immutable.
 */
enum VersionState: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    /** Back with the professor, editable again. */
    case Rejected = 'rejected';
    /** The live version. */
    case Approved = 'approved';
    /** An earlier live version, kept for history. */
    case Superseded = 'superseded';

    /** The professor may edit it. */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Rejected;
    }
}
