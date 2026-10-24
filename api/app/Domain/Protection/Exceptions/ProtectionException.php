<?php

declare(strict_types=1);

namespace App\Domain\Protection\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/**
 * Content protection errors (PRD-07). Each has a stable `code` rendered by the API error envelope.
 */
final class ProtectionException extends DomainException
{
    public static function notPlayable(): self
    {
        return new self('playback.not_playable', 422);
    }

    public static function mediaNotReady(): self
    {
        return new self('playback.media_not_ready', 409);
    }

    public static function previewOnly(): self
    {
        return new self('playback.preview_only', 403);
    }

    public static function streamReplaced(): self
    {
        return new self('playback.stream_replaced', 409);
    }

    public static function notADocument(): self
    {
        return new self('document.not_a_document', 422);
    }

    public static function documentUnavailable(): self
    {
        return new self('document.unavailable', 409);
    }
}
