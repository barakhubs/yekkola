<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

final class IdempotencyException extends DomainException
{
    public static function missingKey(): self
    {
        return new self(errorCode: 'idempotency.key_missing', status: 400);
    }

    public static function keyReused(): self
    {
        return new self(errorCode: 'idempotency.key_reused', status: 422);
    }

    public static function inProgress(): self
    {
        return new self(errorCode: 'idempotency.in_progress', status: 409);
    }
}
