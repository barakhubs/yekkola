<?php

declare(strict_types=1);

namespace App\Domain\Identity\Events;

use App\Domain\Identity\Models\Device;

final readonly class DeviceRevoked
{
    public function __construct(public Device $device) {}
}
