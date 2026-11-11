<?php

declare(strict_types=1);

namespace App\Domain\Protection\Listeners;

use App\Domain\Identity\Events\DeviceRevoked;
use App\Domain\Protection\Models\OfflineLicense;

/**
 * A removed device loses its offline licenses (PRD-07 BR-04): no renewals, and its downloads are deleted at
 * its next sync if it comes online. Until then its stored keys work until they expire (BR-02).
 */
final class RevokeOfflineLicenses
{
    public function handle(DeviceRevoked $event): void
    {
        OfflineLicense::query()->where('device_id', $event->device->id)->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
    }
}
