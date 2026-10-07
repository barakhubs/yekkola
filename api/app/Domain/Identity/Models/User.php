<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Platform\Models\Province;
use App\Domain\Shared\ValueObjects\PhoneNumber;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * A Yekkola account. The phone number (E.164) is the identity; sign-in is by SMS OTP.
 * Use `$user->phone` (a PhoneNumber) in domain code; `phone_e164` is the storage column.
 *
 * @property string $id
 * @property string $phone_e164
 * @property-read PhoneNumber $phone
 * @property \Illuminate\Support\Carbon|null $phone_verified_at
 * @property string|null $name
 * @property string|null $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string $locale
 * @property string|null $province_id
 * @property string|null $city
 * @property UserStatus $status
 * @property int $auth_epoch
 * @property \Illuminate\Support\Carbon|null $last_login_at
 * @property \Illuminate\Support\Carbon|null $last_seen_at
 * @property \Illuminate\Support\Carbon|null $deletion_requested_at
 * @property \Illuminate\Support\Carbon $created_at
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

    /** Mirrors the column defaults so freshly created models are complete without a reload. */
    protected $attributes = [
        'locale' => 'fr',
        'status' => 'active',
        'auth_epoch' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'deletion_requested_at' => 'datetime',
            'auth_epoch' => 'integer',
            'status' => UserStatus::class,
        ];
    }

    /**
     * @return Attribute<PhoneNumber, never>
     */
    protected function phone(): Attribute
    {
        return Attribute::get(fn (): PhoneNumber => PhoneNumber::fromString($this->phone_e164));
    }

    /**
     * @return BelongsTo<Province, $this>
     */
    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    /**
     * @return HasMany<Device, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /**
     * Sign-in is by SMS code only: there is no password. Session integrity is enforced with auth_epoch
     * (EnsureAccountActive), so Laravel's password-hash session check sees a constant.
     */
    public function getAuthPassword(): string
    {
        return '';
    }

    /** No "remember me" tokens (no remember_token column). */
    public function getRememberTokenName(): string
    {
        return '';
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function isBanned(): bool
    {
        return $this->status === UserStatus::Banned;
    }

    public function isSuspended(): bool
    {
        return $this->status === UserStatus::Suspended;
    }
}
