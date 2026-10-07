<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Identity\Models\Device;
use App\Domain\Identity\Models\PersonalAccessToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Device
 */
final class DeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $token = $request->user()?->currentAccessToken();

        return [
            'id' => $this->id,
            'platform' => $this->platform->value,
            'name' => $this->name,
            'app_version' => $this->app_version,
            'registered_at' => $this->registered_at->toIso8601String(),
            'last_active_at' => $this->last_active_at?->toIso8601String(),
            'is_current' => $token instanceof PersonalAccessToken && $token->device_id === $this->id,
        ];
    }
}
