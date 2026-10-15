<?php

declare(strict_types=1);

namespace App\Domain\Professors\Models;

use App\Domain\Commerce\Enums\MobileMoneyRail;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Province;
use App\Domain\Professors\Enums\KycStatus;
use App\Domain\Professors\Enums\ProfessorStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * An approved professor (PRD-02 FR-05/07), created when the application is approved.
 * The verified badge means KYC is verified. The payout number is encrypted at rest.
 *
 * @property string $id
 * @property string $user_id
 * @property string|null $application_id
 * @property string $slug
 * @property \Illuminate\Support\Carbon|null $slug_changed_at
 * @property string $display_name
 * @property string|null $headline
 * @property string|null $bio
 * @property list<string> $subjects
 * @property list<array{label: string, url: string}> $links
 * @property string|null $province_id
 * @property ProfessorStatus $status
 * @property \Illuminate\Support\Carbon|null $suspended_at
 * @property string|null $suspension_reason
 * @property KycStatus $kyc_status
 * @property \Illuminate\Support\Carbon|null $verified_at
 * @property int|null $revenue_share_bps
 * @property MobileMoneyRail|null $payout_rail
 * @property string|null $payout_msisdn
 * @property string|null $payout_name
 * @property \Illuminate\Support\Carbon|null $payout_method_updated_at
 * @property \Illuminate\Support\Carbon|null $payout_hold_until
 * @property \Illuminate\Support\Carbon $created_at
 */
final class ProfessorProfile extends Model implements HasMedia
{
    use HasUlids;
    use InteractsWithMedia;

    public const AVATAR = 'avatar';

    protected $guarded = [];

    protected $hidden = ['payout_msisdn'];

    protected $attributes = [
        'subjects' => '[]',
        'links' => '[]',
        'status' => 'active',
        'kyc_status' => 'none',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subjects' => 'array',
            'links' => 'array',
            'status' => ProfessorStatus::class,
            'kyc_status' => KycStatus::class,
            'payout_rail' => MobileMoneyRail::class,
            'payout_msisdn' => 'encrypted',
            'revenue_share_bps' => 'integer',
            'slug_changed_at' => 'datetime',
            'suspended_at' => 'datetime',
            'verified_at' => 'datetime',
            'payout_method_updated_at' => 'datetime',
            'payout_hold_until' => 'datetime',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::AVATAR)
            ->singleFile()
            ->useDisk('public')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Province, $this>
     */
    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    /**
     * @return HasMany<KycSubmission, $this>
     */
    public function kycSubmissions(): HasMany
    {
        return $this->hasMany(KycSubmission::class);
    }

    /**
     * Shown publicly: approved professors who are not suspended (FR-12).
     *
     * @param  Builder<self>  $query
     */
    public function scopePublic(Builder $query): void
    {
        $query->where('status', ProfessorStatus::Active);
    }

    public function isActive(): bool
    {
        return $this->status === ProfessorStatus::Active;
    }

    public function isVerified(): bool
    {
        return $this->kyc_status === KycStatus::Verified;
    }

    public function canChangeSlug(): bool
    {
        return $this->slug_changed_at === null;
    }

    public function hasPayoutMethod(): bool
    {
        return $this->payout_rail !== null && $this->payout_msisdn !== null;
    }

    /**
     * Eligible to receive payouts right now (PRD-02 BR-01, BR-03; FR-09 hold).
     */
    public function isPayable(): bool
    {
        return $this->isActive()
            && $this->isVerified()
            && $this->hasPayoutMethod()
            && ($this->payout_hold_until === null || $this->payout_hold_until->isPast());
    }

    public function avatarUrl(): ?string
    {
        $url = $this->getFirstMediaUrl(self::AVATAR);

        return $url !== '' ? $url : null;
    }
}
