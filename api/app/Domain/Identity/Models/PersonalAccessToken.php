<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum token with a ULID primary key, bound to a registered device (registered in AppServiceProvider).
 *
 * @property string|null $device_id
 */
// Not final: Sanctum::actingAs() mocks the token model in tests.
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    use HasUlids;

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
