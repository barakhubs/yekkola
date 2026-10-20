<?php

declare(strict_types=1);

namespace App\Domain\Learning\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/**
 * Learning errors (PRD-06). Each has a stable `code` rendered by the API error envelope.
 */
final class LearningException extends DomainException
{
    public static function notFree(): self
    {
        return new self('enrollment.not_free', 422);
    }

    public static function revoked(): self
    {
        return new self('enrollment.revoked', 409);
    }

    public static function enrollmentRequired(): self
    {
        return new self('enrollment.required', 403);
    }

    public static function lessonNotFound(): self
    {
        return new self('lesson.not_found', 404);
    }

    public static function notAQuiz(): self
    {
        return new self('quiz.not_a_quiz', 422);
    }

    public static function maxAttemptsReached(int $max): self
    {
        return new self('quiz.max_attempts_reached', 409, ['max_attempts' => $max]);
    }

    public static function alreadySubmitted(): self
    {
        return new self('quiz.already_submitted', 409);
    }
}
