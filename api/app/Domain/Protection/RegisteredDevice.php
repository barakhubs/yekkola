<?php

declare(strict_types=1);

namespace App\Domain\Protection;

use App\Domain\Identity\Models\Device;
use App\Domain\Identity\Models\User;
use App\Domain\Protection\Exceptions\ProtectionException;

/**
 * The mobile device making a request, for what only a registered device may do (offline licenses and their
 * sync, PRD-07 FR-06/FR-07): the token's device, still active, and the same install that is asking
 * (`X-Device-Id`). Web sessions and copied tokens get `offline.device_required`.
 */
final class RegisteredDevice
{
    public function resolve(User $user, ?string $installId): Device
    {
        $deviceId = $user->currentDeviceId();
        $device = $deviceId === null ? null : Device::query()->whereKey($deviceId)->where('user_id', $user->id)->first();

        if ($device === null || ! $device->isActive() || $installId === null || ! hash_equals($device->install_id, $installId)) {
            throw ProtectionException::registeredDeviceRequired();
        }

        return $device;
    }
}
