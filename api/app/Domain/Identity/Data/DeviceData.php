<?php

declare(strict_types=1);

namespace App\Domain\Identity\Data;

use App\Domain\Identity\Enums\DevicePlatform;

/**
 * A mobile install signing in (PRD-01 FR-06).
 */
final readonly class DeviceData
{
    public function __construct(
        public string $installId,
        public DevicePlatform $platform,
        public ?string $name = null,
        public ?string $appVersion = null,
        public ?string $pushToken = null,
    ) {}
}
