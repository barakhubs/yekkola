<?php

declare(strict_types=1);

namespace App\Integrations;

use App\Domain\Shared\Exceptions\DomainException;

/**
 * Signed correctly, but missing the fields we need (event id, reference, kind).
 */
final class InvalidWebhookPayload extends DomainException
{
    public static function missing(string $field): self
    {
        return new self(errorCode: 'webhook.invalid_payload', status: 400, context: ['field' => $field]);
    }
}
