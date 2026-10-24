<?php

declare(strict_types=1);

namespace App\Domain\Authoring\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/**
 * Course authoring errors (PRD-03). Each has a stable `code` rendered by the API error envelope.
 */
final class AuthoringException extends DomainException
{
    public static function draftNotEditable(): self
    {
        return new self('course.draft_not_editable', 409);
    }

    public static function noDraft(): self
    {
        return new self('course.no_draft', 404);
    }

    public static function draftExists(): self
    {
        return new self('course.draft_exists', 409);
    }

    public static function notPublished(): self
    {
        return new self('course.not_published', 409);
    }

    public static function invalidTransition(): self
    {
        return new self('course.invalid_transition', 409);
    }

    /**
     * @param  list<string>  $missing
     */
    public static function notReadyForReview(array $missing): self
    {
        return new self('course.not_ready_for_review', 422, ['missing' => $missing]);
    }

    public static function freeCourseLimit(?int $limit): self
    {
        return new self('course.free_limit_reached', 422, ['limit' => $limit]);
    }

    public static function freeHoursLimit(int $hours): self
    {
        return new self('course.free_hours_limit_reached', 422, ['limit' => $hours]);
    }

    public static function previewLimit(int $max): self
    {
        return new self('lesson.preview_limit_reached', 422, ['max' => $max]);
    }

    public static function documentNotSupported(): self
    {
        return new self('lesson.document_not_supported', 422);
    }

    public static function lessonTypeMismatch(): self
    {
        return new self('lesson.type_mismatch', 422);
    }

    public static function cannotDeletePublished(): self
    {
        return new self('course.cannot_delete_published', 409);
    }

    public static function professorSuspended(): self
    {
        return new self('professor.suspended', 403);
    }

    public static function ownCourse(): self
    {
        return new self('course.own_course', 403);
    }

    public static function invalidOrder(): self
    {
        return new self('course.invalid_order', 422);
    }
}
