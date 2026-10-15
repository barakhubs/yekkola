<?php

declare(strict_types=1);

namespace App\Domain\Authoring\Enums;

/**
 * Course lifecycle (PRD-03). A published course can have a draft in review without going offline.
 */
enum CourseStatus: string
{
    /** Never published. */
    case Draft = 'draft';
    /** First version submitted, never published. */
    case InReview = 'in_review';
    case Published = 'published';
    /** No new enrollments; existing students keep access. */
    case Unpublished = 'unpublished';
    /** Hidden everywhere except for enrolled students. */
    case Archived = 'archived';
}
