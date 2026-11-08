<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Models;

use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A user's choice for one event type on one channel (PRD-10 FR-05). Rows exist only for overrides; no row means on.
 *
 * @property string $id
 * @property string $user_id
 * @property NotificationType $type
 * @property NotificationChannel $channel
 * @property bool $enabled
 */
final class NotificationPreference extends Model
{
    use HasUlids;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['type' => NotificationType::class, 'channel' => NotificationChannel::class, 'enabled' => 'boolean'];
    }

    /**
     * @return list<NotificationChannel>
     */
    public static function disabledChannels(string $userId, NotificationType $type): array
    {
        return array_values(self::query()->where('user_id', $userId)->where('type', $type)->where('enabled', false)
            ->get()->map(fn (self $p) => $p->channel)->all());
    }
}
