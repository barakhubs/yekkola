<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Platform\Models\Province;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * A Yekkola account. The phone number (E.164) is the identity; sign-in is by SMS OTP.
 *
 * @property string $id
 * @property string $phone_e164
 * @property \Illuminate\Support\Carbon|null $phone_verified_at
 * @property string|null $name
 * @property string|null $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string $locale
 * @property string|null $province_id
 * @property string|null $city
 * @property UserStatus $status
 * @property \Illuminate\Support\Carbon|null $last_seen_at
 */
#[Fillable(['phone_e164', 'name', 'email', 'locale', 'province_id', 'city'])]
#[Hidden(['phone_e164', 'email'])]
#[UseFactory(UserFactory::class)]
final class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use HasUlids;
    use Notifiable;
    use SoftDeletes;

    /** Roles and permissions use one guard whether the user arrived by session or bearer token. */
    protected string $guard_name = 'web';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'status' => UserStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Province, $this>
     */
    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }
}
