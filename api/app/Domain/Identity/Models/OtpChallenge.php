<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\OtpPurpose;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * One SMS code sent to a phone. The code itself is never stored — only an HMAC of it.
 *
 * @property string $id
 * @property string $phone_e164
 * @property OtpPurpose $purpose
 * @property string|null $user_id
 * @property string $code_hash
 * @property int $attempts
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $consumed_at
 * @property \Illuminate\Support\Carbon $created_at
 */
final class OtpChallenge extends Model
{
    use HasUlids;
    use MassPrunable;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => OtpPurpose::class,
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }

    /**
     * Challenges older than a day carry no value — prune them (`model:prune`).
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return self::query()->where('created_at', '<', now()->subDay());
    }
}
