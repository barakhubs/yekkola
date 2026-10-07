<?php

declare(strict_types=1);

namespace App\Domain\Identity\Data;

/**
 * Who is calling, as far as auth decisions care. Built by the HTTP layer; actions decide policy.
 */
final readonly class ClientContext
{
    public function __construct(
        /** Request came from a Yekkola web app (Sanctum stateful origin). */
        public bool $isBrowser,
        /** Mobile app request (sends X-Device-Id). App attestation replaces this check in phase 3. */
        public ?string $deviceId,
        public ?string $botToken,
        public ?string $ip,
        public ?string $userAgent,
        public string $locale,
    ) {}
}
