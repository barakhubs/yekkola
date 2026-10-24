<?php

declare(strict_types=1);

namespace App\Domain\Protection\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One web playback (PRD-07 FR-05). Live while it isn't ended and its heartbeat is recent; when a new stream
 * would go over the limit, the oldest live one is ended ("replaced") and its player stops.
 *
 * @property string $id
 * @property string $user_id
 * @property string $lesson_uid
 * @property string $media_asset_id
 * @property \Illuminate\Support\Carbon $last_heartbeat_at
 * @property \Illuminate\Support\Carbon|null $ended_at
 * @property string|null $ended_reason
 * @property \Illuminate\Support\Carbon $created_at
 */
final class StreamSession extends Model
{
    use HasUlids;

    public const REPLACED = 'replaced';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_heartbeat_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('ended_at')
            ->where('last_heartbeat_at', '>=', now()->subSeconds((int) config('yekkola.protection.stream_stale_seconds')));
    }

    public function isEnded(): bool
    {
        return $this->ended_at !== null;
    }
}
