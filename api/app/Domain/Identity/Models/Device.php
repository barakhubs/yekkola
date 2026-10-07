<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\DevicePlatform;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A registered mobile install (PRD-01 FR-06/07). Active devices count against the device limit;
 * each holds at most one API token.
 *
 * @property string $id
 * @property string $user_id
 * @property DevicePlatform $platform
 * @property string $install_id
 * @property string|null $name
 * @property string|null $app_version
 * @property string|null $push_token
 * @property \Illuminate\Support\Carbon $registered_at
 * @property \Illuminate\Support\Carbon|null $last_active_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 */
final class Device extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $hidden = ['push_token', 'install_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => DevicePlatform::class,
            'registered_at' => 'datetime',
            'last_active_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<PersonalAccessToken, $this>
     */
    public function tokens(): HasMany
    {
        return $this->hasMany(PersonalAccessToken::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
