<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

use RuntimeException;

/**
 * Base for business-rule failures. Rendered by the API as the standard error envelope
 * with a stable machine-readable `code` (see docs/architecture.md §4.1).
 *
 * Titles are translated from `lang/{locale}/errors.php` using the code as key.
 */
abstract class DomainException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context  Extra fields returned to the client (never secrets or PII).
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status = 422,
        public readonly array $context = [],
        ?string $message = null,
    ) {
        parent::__construct($message ?? $errorCode);
    }
}
