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
}
