<?php

declare(strict_types=1);

namespace App\Integrations;

use App\Domain\Shared\Exceptions\DomainException;

final class InvalidWebhookSignature extends DomainException
{
    public static function make(): self
    {
        return new self(errorCode: 'webhook.invalid_signature', status: 401);
    }
}
