<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/**
 * Report errors (PRD-06 FR-14, PRD-09 FR-10). Each has a stable `code` rendered by the API error envelope.
 */
final class ModerationException extends DomainException
{
    public static function targetNotFound(): self
    {
        return new self('report.target_not_found', 404);
    }

    public static function ownContent(): self
    {
        return new self('report.own_content', 422);
    }

    public static function alreadyResolved(): self
    {
        return new self('report.already_resolved', 409);
    }

    public static function actionNotAllowed(): self
    {
        return new self('report.action_not_allowed', 403);
    }
}
