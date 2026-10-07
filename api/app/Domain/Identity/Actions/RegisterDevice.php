<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Data\DeviceData;
use App\Domain\Identity\Exceptions\IdentityException;
use App\Domain\Identity\Models\Device;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Settings\ProtectionSettings;

/**
 * Registers (or re-activates) a mobile install for a user, enforcing the device limit (PRD-01 FR-07/08,
 * PRD-07 FR-06). The same install signing in again is the same device, not a new one.
 * At the limit, the caller may name an active device to replace; otherwise `device.limit_reached`
 * lists the active devices so the user can pick one.
 */
final class RegisterDevice
{
    public function __construct(
        private readonly ProtectionSettings $settings,
        private readonly RevokeDevice $revokeDevice,
    ) {}

    public function handle(User $user, DeviceData $data, ?string $replaceDeviceId = null): Device
    {
        $existing = Device::query()
            ->where('user_id', $user->id)
            ->where('install_id', $data->installId)
            ->lockForUpdate()
            ->first();

        if ($existing === null || ! $existing->isActive()) {
            $this->makeRoom($user, $replaceDeviceId);
        }

        $device = $existing ?? new Device(['user_id' => $user->id, 'install_id' => $data->installId]);

        $device->fill([
            'platform' => $data->platform,
            'name' => $data->name,
            'app_version' => $data->appVersion,
            'push_token' => $data->pushToken,
            'last_active_at' => now(),
            'revoked_at' => null,
        ]);
        $device->registered_at ??= now();
        $device->save();

        return $device;
    }

    private function makeRoom(User $user, ?string $replaceDeviceId): void
    {
        $active = Device::query()->where('user_id', $user->id)->active()->orderByDesc('last_active_at')->get();
        $limit = $this->settings->registered_device_limit;

        if ($active->count() < $limit) {
            return;
        }

        $replace = $replaceDeviceId === null ? null : $active->firstWhere('id', $replaceDeviceId);

        if ($replace === null) {
            throw IdentityException::deviceLimitReached($active, $limit);
        }

        $this->revokeDevice->handle($replace);

        // If the limit was lowered below the user's active count, also sign out the least recently used
        // surplus devices so the user isn't stuck (PRD-07 BR-03).
        $active->reject(fn ($d) => $d->id === $replace->id)
            ->sortBy(fn ($d) => $d->last_active_at?->getTimestamp() ?? 0)
            ->take(max(0, $active->count() - $limit))
            ->each(fn ($d) => $this->revokeDevice->handle($d));
    }
}
