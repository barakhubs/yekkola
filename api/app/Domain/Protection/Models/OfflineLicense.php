<?php

declare(strict_types=1);

namespace App\Domain\Protection\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An offline download granted to one registered device for one lesson (PRD-07 FR-07). Renewing updates the
 * same row. It can't be revoked on the phone remotely (BR-02): a revoked or expired license tells the app,
 * at its next sync, to delete the download; until then the stored key works until `expires_at`.
 *
 * @property string $id
 * @property string $user_id
 * @property string $device_id
 * @property string $lesson_uid
 * @property string $media_asset_id
 * @property string $rendition
 * @property \Illuminate\Support\Carbon $issued_at
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 */
final class OfflineLicense extends Model
{
    use HasUlids;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }
}
