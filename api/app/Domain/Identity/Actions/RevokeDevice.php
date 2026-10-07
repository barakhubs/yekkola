<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Events\DeviceRevoked;
use App\Domain\Identity\Models\Device;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Removes a device: its API token stops working immediately; offline licenses it holds are revoked
 * by listeners (PRD-07 BR-04) and deleted at its next sync.
 */
final class RevokeDevice
{
    public function __construct(private readonly Dispatcher $events) {}

    public function handle(Device $device): void
    {
        if (! $device->isActive()) {
            return;
        }

        $device->tokens()->delete();
        $device->forceFill(['revoked_at' => now(), 'push_token' => null])->save();

        $this->events->dispatch(new DeviceRevoked($device));
    }
}
